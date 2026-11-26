<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-token gate for the partner API.
 *
 * Implements AuthenticatesRequests so the router sorts it before
 * ThrottleRequests: the per-visitor limit trusts X-Client-IP, and that header
 * may only count once the token has been accepted.
 */
class AuthenticatePartner implements AuthenticatesRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->bearerToken();
        $client = $token === '' ? null : $this->clientFor($token);

        if ($client === null) {
            // A coarse per-IP brake so a flood of bad tokens cannot flood the
            // log too: past the limit the caller is refused without a log line.
            $key = 'partner-api-auth:'.$request->ip();

            if (RateLimiter::tooManyAttempts($key, (int) config('domain-checker.partner_api.failed_auth_per_minute'))) {
                return response()->json(['message' => 'Too Many Attempts.'], 429, ['Retry-After' => RateLimiter::availableIn($key)]);
            }

            RateLimiter::hit($key);

            // Neither the token nor the caller's address belongs in a log file.
            Log::warning('Partner API request rejected', [
                'reason' => $token === '' ? 'missing token' : 'unknown token',
                'path' => $request->path(),
            ]);

            return response()->json(['message' => 'Unauthenticated.'], 401, ['WWW-Authenticate' => 'Bearer']);
        }

        $request->attributes->set('partner_client', $client);

        return $next($request);
    }

    /**
     * Only SHA-256 hashes are configured, as comma-separated name:hash pairs.
     * Two entries may share a name, which is how a token is rotated.
     */
    private function clientFor(string $token): ?string
    {
        $presented = hash('sha256', $token);

        foreach (explode(',', (string) config('domain-checker.partner_api.clients')) as $entry) {
            [$name, $hash] = array_pad(explode(':', trim($entry), 2), 2, '');

            if ($name !== '' && hash_equals(strtolower(trim($hash)), $presented)) {
                return $name;
            }
        }

        return null;
    }
}
