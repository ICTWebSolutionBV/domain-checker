<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class DomainAvailabilityService
{
    public function __construct(
        private readonly RdapService $rdap,
        private readonly WhoisService $whois,
    ) {}

    /**
     * Check a batch of TLDs for a given domain name.
     * Returns ['com' => 'available'|'taken'|'unknown', ...]
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

        $rdapResults = $this->rdap->checkBatch($domain, $uncached);

        foreach ($uncached as $tld) {
            $rdapResult = $rdapResults[$tld] ?? null;

            if ($rdapResult !== null) {
                $status = $rdapResult;
            } else {
                $status = $this->whois->check($domain, $tld);
            }

            $ttl = config('domain-checker.cache.result_ttl', 900);
            Cache::put("domain_check_{$domain}_{$tld}", $status, $ttl);

            $results[$tld] = $status;
        }

        return $results;
    }
}
