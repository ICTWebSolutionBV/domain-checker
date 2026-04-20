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
     * Check a batch of TLDs for a given domain name.
     * Returns ['com' => 'available'|'taken'|'unknown', ...]
     *
     * When a Realtime Register API key is configured:
     *   - RTR requests and RDAP requests are fired simultaneously in one pool.
     *   - RTR result is preferred; RDAP is used if RTR returns null (error / unsupported TLD).
     *   - If both return null, WHOIS is tried as a last resort.
     *
     * Without an RTR key the original RDAP → WHOIS chain is used.
     *
     * @param  array<string>  $tlds
     * @return array<string, string>
     */
    public function checkBatch(string $domain, array $tlds): array
    {
        $domain = strtolower(trim($domain));
        $results = [];
        $uncached = [];

        foreach ($tlds as $tld) {
            $cacheKey = "domain_check_{$domain}_{$tld}";
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                $results[$tld] = $cached;
            } else {
                $uncached[] = $tld;
            }
        }

        if (empty($uncached)) {
            return $results;
        }

        $fresh = $this->rtr->isConfigured()
            ? $this->checkWithIsProxy($domain, $uncached)
            : $this->checkWithRdap($domain, $uncached);

        $ttl = config('domain-checker.cache.result_ttl', 900);

        foreach ($uncached as $tld) {
            $status = $fresh[$tld] ?? 'unknown';
            Cache::put("domain_check_{$domain}_{$tld}", $status, $ttl);
            $results[$tld] = $status;
        }

        return $results;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Check via Realtime Register IsProxy socket protocol.
     * IsProxy pipelines all IS commands over one TLS connection — the server
     * processes them in parallel — making this faster than N RDAP requests.
     * Any TLD that IsProxy returns null for falls through to RDAP → WHOIS.
     *
     * @param  array<string>  $tlds
     * @return array<string, string>
     */
    private function checkWithIsProxy(string $domain, array $tlds): array
    {
        $isProxyResults = $this->rtr->checkBatch($domain, $tlds);

        $results       = [];
        $rdapFallback  = [];

        foreach ($tlds as $tld) {
            $result = $isProxyResults[$tld] ?? null;

            if ($result !== null) {
                $results[$tld] = $result;
            } else {
                $rdapFallback[] = $tld;
            }
        }

        // RDAP fallback for TLDs IsProxy couldn't handle
        if (! empty($rdapFallback)) {
            $rdapResults = $this->rdap->checkBatch($domain, $rdapFallback);

            foreach ($rdapFallback as $tld) {
                $rdapResult    = $rdapResults[$tld] ?? null;
                $results[$tld] = $rdapResult !== null
                    ? $rdapResult
                    : $this->whois->check($domain, $tld);
            }
        }

        return $results;
    }

    /**
     * Original RDAP → WHOIS chain used when no RTR key is configured.
     *
     * @param  array<string>  $tlds
     * @return array<string, string>
     */
    private function checkWithRdap(string $domain, array $tlds): array
    {
        $rdapResults = $this->rdap->checkBatch($domain, $tlds);
        $results = [];

        foreach ($tlds as $tld) {
            $rdapResult = $rdapResults[$tld] ?? null;
            $results[$tld] = $rdapResult !== null
                ? $rdapResult
                : $this->whois->check($domain, $tld);
        }

        return $results;
    }

}
