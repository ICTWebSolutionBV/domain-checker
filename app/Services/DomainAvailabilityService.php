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
     * @param  array<string>  $tlds
     * @param  callable(string, string): void  $onResult
     */
    public function streamCheck(string $domain, array $tlds, callable $onResult): void
    {
        $domain = strtolower(trim($domain));
        $writes = new VerdictCache($domain);
        $uncached = [];

        // Emit cached results immediately. One Cache::many() instead of one
        // Cache::get() per TLD: against the database store the full IANA list
        // was 1287 SELECTs (0.36 s) where a batched read is a single query.
        $cached = $writes->many($tlds);

        foreach ($tlds as $tld) {
            if (isset($cached[$tld])) {
                $onResult($tld, $cached[$tld]);
            } else {
                $uncached[] = $tld;
            }
        }

        if (empty($uncached)) {
            return;
        }

        try {
            $emit = function (string $tld, string $status) use ($writes, $onResult): void {
                $writes->put($tld, $status);
                $onResult($tld, $status);
            };

            if ($this->rtr->isConfigured()) {
                // Pipeline ALL IS commands at once — server processes in parallel
                $fallback = $this->rtr->pipelineStream($domain, $uncached, $emit);

                // RDAP → WHOIS for TLDs the IsProxy couldn't resolve
                if (! empty($fallback)) {
                    $this->streamWithRdap($domain, $fallback, $emit);
                }
            } else {
                $this->streamWithRdap($domain, $uncached, $emit);
            }
        } finally {
            $writes->flush();
        }
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * RDAP → WHOIS chain, emitting results via $onResult as each TLD resolves.
     *
     * Both legs are now concurrent. RDAP runs as one connection-reusing batch
     * instead of 129 sequential pools of ten, and the TLDs it cannot answer go
     * into a concurrent WHOIS wave instead of being walked one socket pair at a
     * time — which was the dominant cost of a full-list run and emitted nothing
     * to the stream while it ran.
     *
     * @param  array<string>  $tlds
     * @param  callable(string, string): void  $onResult
     */
    private function streamWithRdap(string $domain, array $tlds, callable $onResult): void
    {
        $chunkSize = max(1, (int) config('domain-checker.batch_size', 250));
        $needsWhois = [];

        foreach (array_chunk($tlds, $chunkSize) as $batch) {
            foreach ($this->rdap->streamCheck($domain, $batch, $onResult) as $tld) {
                $needsWhois[] = $tld;
            }
        }

        if ($needsWhois === []) {
            return;
        }

        $this->whois->streamCheck($domain, $needsWhois, $onResult);
    }
}
