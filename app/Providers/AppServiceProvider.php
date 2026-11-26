<?php

namespace App\Providers;

use App\Support\VisitorIp;
use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->pinUrlGenerationToAppUrl();

        RateLimiter::for('domain-check', function (Request $request) {
            return $request->user()
                ? Limit::perMinute(60)->by($request->user()->id)
                : Limit::perMinute(10)->by($request->ip());
        });

        RateLimiter::for('http3-check', function (Request $request) {
            return $request->user()
                ? Limit::perMinute(30)->by($request->user()->id)
                : Limit::perHour(60)->by($request->ip());
        });

        RateLimiter::for('ip-lookup', function (Request $request) {
            return $request->user()
                ? Limit::perMinute(45)->by($request->user()->id)
                : Limit::perHour(60)->by($request->ip());
        });

        RateLimiter::for('redirect-check', function (Request $request) {
            return $request->user()
                ? Limit::perMinute(30)->by($request->user()->id)
                : Limit::perHour(60)->by($request->ip());
        });

        RateLimiter::for('dns-bulk', function (Request $request) {
            return $request->user()
                ? Limit::perMinute(30)->by($request->user()->id)
                : Limit::perMinute(5)->by($request->ip());
        });

        // A list of TLDs and scope=all share one route but not one budget: a
        // full check is ~1,300 lookups, so it gets its own, slower limits.
        RateLimiter::for('partner-api', fn (Request $request) => $request->input('scope') === 'all'
            ? $this->partnerApiLimits(
                $request,
                'all',
                Limit::perHour((int) config('domain-checker.partner_api.all_per_client_per_hour')),
                Limit::perMinutes(10, (int) config('domain-checker.partner_api.all_per_visitor_per_10_minutes')),
            )
            : $this->partnerApiLimits(
                $request,
                'check',
                Limit::perMinute((int) config('domain-checker.partner_api.per_client_per_minute')),
                Limit::perMinute((int) config('domain-checker.partner_api.per_visitor_per_minute')),
            ));

        RateLimiter::for('partner-api-bulk', fn (Request $request) => $this->partnerApiLimits(
            $request,
            'bulk',
            Limit::perMinute((int) config('domain-checker.partner_api.bulk_per_client_per_minute')),
            Limit::perMinute((int) config('domain-checker.partner_api.bulk_per_visitor_per_minute')),
        ));
    }

    /**
     * The token limit, plus the visitor limit when X-Client-IP is usable.
     *
     * Runs after AuthenticatePartner, so X-Client-IP comes from a client whose
     * token we accepted. Without a valid IP only the token limit holds.
     *
     * @return array<Limit>
     */
    private function partnerApiLimits(Request $request, string $endpoint, Limit $perClient, Limit $perVisitor): array
    {
        $client = (string) $request->attributes->get('partner_client');
        $visitor = (string) $request->header('X-Client-IP');
        $bucket = VisitorIp::bucket($visitor);

        $limits = [
            $perClient->by($endpoint.':client:'.$client)->response($this->partnerApiLimited($client, $endpoint, 'client')),
        ];

        if ($bucket === null) {
            // A partner that stops sending the header loses the per-visitor
            // limit without any error, so say so (never the value itself).
            Log::notice('Partner API request without a usable X-Client-IP', [
                'client' => $client,
                'reason' => $visitor === '' ? 'missing' : 'invalid',
            ]);

            return $limits;
        }

        $limits[] = $perVisitor->by($endpoint.':visitor:'.$client.':'.$bucket)
            ->response($this->partnerApiLimited($client, $endpoint, 'visitor'));

        return $limits;
    }

    /**
     * A JSON 429 that keeps Retry-After, logged without the visitor's address.
     */
    private function partnerApiLimited(string $client, string $endpoint, string $limit): Closure
    {
        return function (Request $request, array $headers) use ($client, $endpoint, $limit): JsonResponse {
            Log::notice('Partner API rate limit reached', ['client' => $client, 'endpoint' => $endpoint, 'limit' => $limit]);

            return response()->json(['message' => 'Too Many Attempts.'], 429, $headers);
        };
    }

    /**
     * Generate every URL from APP_URL instead of from the incoming request.
     *
     * Without this, a request carrying someone else's host produces links on
     * that host -- and a password-reset mail then delivers a valid token to a
     * server the attacker controls. TrustHosts covers this too, but Laravel
     * disables it in the local environment, and mail sent from a queue or a
     * console command has no request to take a host from at all.
     */
    private function pinUrlGenerationToAppUrl(): void
    {
        $appUrl = (string) config('app.url');

        if ($appUrl === '') {
            return;
        }

        URL::forceRootUrl($appUrl);

        if (str_starts_with($appUrl, 'https://')) {
            URL::forceScheme('https');
        }
    }
}
