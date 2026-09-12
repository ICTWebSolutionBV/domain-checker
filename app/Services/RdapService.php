<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RdapService
{
    public function __construct(private readonly TldRepository $tldRepository) {}

    /**
     * Check every RDAP-capable TLD for a domain, calling $onResult($tld, $status)
     * as each answer lands. Returns the TLDs RDAP could not answer, so the
     * caller can fall back to WHOIS for those.
     *
     * One Http::batch() for the whole list replaces the old chunk-of-ten pools.
     * Each pool built its own Guzzle handler, so 46 popular TLDs meant 5
     * sequential rounds -- each paying the slowest of its ten requests and a
     * fresh TLS handshake per server -- and the full IANA list meant 129 of
     * them. A batch shares one curl multi handle, so connections to the big
     * registries (Verisign answers .com, .net, .cc, ...) are reused, results
     * stream back as they complete instead of per round, and the concurrency
     * ceiling is a config value rather than the chunk size.
     *
     * @param  array<string>  $tlds
     * @param  callable(string, string): void  $onResult
     * @return array<string> TLDs that need a fallback check
     */
    public function streamCheck(string $domain, array $tlds, callable $onResult): array
    {
        $targets = [];
        $fallback = [];

        foreach ($tlds as $tld) {
            $server = $this->tldRepository->findRdapServer($tld);

            if ($server) {
                $targets[$tld] = "{$server}/domain/{$domain}.{$tld}";
            } else {
                $fallback[] = $tld;
            }
        }

        if ($targets === []) {
            return $fallback;
        }

        $timeout = (int) config('domain-checker.timeouts.rdap', 5);
        $concurrency = max(1, (int) config('domain-checker.concurrency.rdap', 64));

        // Every RDAP verdict is carried by the status code, and a 404 -- the
        // answer we care about most -- counts as "failed" to the HTTP client, so
        // both callbacks funnel into the same handler.
        $resolved = [];

        $handle = function ($batch, $tld, $result) use (&$resolved, $onResult): void {
            $status = $this->verdictFor($result);

            if ($status === null) {
                return;
            }

            $resolved[$tld] = true;
            $onResult($tld, $status);
        };

        try {
            Http::batch(function ($batch) use ($targets, $timeout) {
                foreach ($targets as $tld => $url) {
                    $batch->as($tld)->timeout($timeout)->get($url);
                }
            })
                ->concurrency($concurrency)
                ->progress($handle)
                ->catch($handle)
                ->send();
        } catch (\Throwable $e) {
            Log::debug('RDAP batch failed', ['error' => $e->getMessage()]);
        }

        // Anything RDAP did not turn into a verdict -- no server, a non-answer
        // status, a transport failure, or a request the batch never reported on
        // -- needs WHOIS. Derived from the target list rather than accumulated
        // in the callbacks, so a rejection shape we did not anticipate cannot
        // make a TLD vanish from the stream.
        foreach (array_keys($targets) as $tld) {
            if (! isset($resolved[$tld])) {
                $fallback[] = $tld;
            }
        }

        return $fallback;
    }

    /**
     * 404 means the registry has no such object; 200 means it has. Anything else
     * (403, 429, a timeout, a transport error) is not an answer.
     */
    private function verdictFor(mixed $result): ?string
    {
        if (! $result instanceof Response) {
            return null;
        }

        return match ($result->status()) {
            404 => 'available',
            200 => 'taken',
            default => null,
        };
    }
}
