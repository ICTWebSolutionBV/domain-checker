<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class DomainAvailabilityService
{
    public function __construct(
        private readonly RdapService $rdap,
        private readonly WhoisService $whois,
        private readonly TldRepository $tldRepository,
        private readonly RealtimeRegisterService $rtr,
    ) {}

    /**
     * Stream check: calls $onResult($tld, $status) for each TLD as the result
     * becomes available.  Cached hits are emitted first, then live checks run.
     *
     * When RTR is configured all IS commands are pipelined over one TLS socket so
     * results stream back in parallel — total time ≈ slowest single TLD.
     * RDAP/WHOIS is used for TLDs the IsProxy service can't resolve.
     *
     * @param  array<string>                   $tlds
     * @param  callable(string, string): void  $onResult
     */
    public function streamCheck(string $domain, array $tlds, callable $onResult): void
    {
        $domain   = strtolower(trim($domain));
        $ttl      = config('domain-checker.cache.result_ttl', 900);
        $uncached = [];

        // Emit cached results immediately
        foreach ($tlds as $tld) {
            $cached = Cache::get("domain_check_{$domain}_{$tld}");
            if ($cached !== null) {
                $onResult($tld, $cached);
            } else {
                $uncached[] = $tld;
            }
        }

        if (empty($uncached)) {
            return;
        }

        if ($this->rtr->isConfigured()) {
            // Pipeline ALL IS commands at once — server processes in parallel
            $fallback = $this->rtr->pipelineStream(
                $domain,
                $uncached,
                function (string $tld, string $status) use ($domain, $ttl, $onResult): void {
                    Cache::put("domain_check_{$domain}_{$tld}", $status, $ttl);
                    $onResult($tld, $status);
                }
            );

            // RDAP → WHOIS for TLDs the IsProxy couldn't resolve
            if (! empty($fallback)) {
                $this->streamWithRdap($domain, $fallback, $onResult, $ttl);
            }
        } else {
            $this->streamWithRdap($domain, $uncached, $onResult, $ttl);
        }
    }

    /**
     * Check a batch of TLDs for a given domain name (blocking, used for cache fill).
     * Returns ['com' => 'available'|'taken'|'unknown', ...]
     *
     * @param  array<string>  $tlds
     * @return array<string, string>
     */
    public function checkBatch(string $domain, array $tlds): array
    {
        $results = [];
        $this->streamCheck($domain, $tlds, function (string $tld, string $status) use (&$results): void {
            $results[$tld] = $status;
        });
        return $results;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * RDAP → WHOIS chain, emitting results via $onResult as each TLD resolves.
     * Processes in batches of 10 for RDAP concurrency, then WHOIS for nulls.
     *
     * @param  array<string>                   $tlds
     * @param  callable(string, string): void  $onResult
     */
    private function streamWithRdap(string $domain, array $tlds, callable $onResult, int $ttl): void
    {
        foreach (array_chunk($tlds, 10) as $batch) {
            $rdapResults = $this->rdap->checkBatch($domain, $batch);

            foreach ($batch as $tld) {
                $rdapResult = $rdapResults[$tld] ?? null;
                $status     = $rdapResult ?? $this->whois->check($domain, $tld) ?? 'unknown';
                Cache::put("domain_check_{$domain}_{$tld}", $status, $ttl);
                $onResult($tld, $status);
            }
        }
    }
}
