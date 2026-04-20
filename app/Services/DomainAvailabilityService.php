<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
            ? $this->checkWithRtr($domain, $uncached)
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
     * Race RTR and RDAP in a single Http::pool() so both run concurrently.
     * RTR result is preferred; RDAP is the fallback for each TLD; WHOIS is
     * used only when both return null.
     *
     * @param  array<string>  $tlds
     * @return array<string, string>
     */
    private function checkWithRtr(string $domain, array $tlds): array
    {
        $apiKey      = \App\Models\Setting::get('realtime_register_api_key', config('domain-checker.realtime_register.api_key', '')) ?? '';
        $baseUrl     = rtrim(\App\Models\Setting::get('realtime_register_base_url', config('domain-checker.realtime_register.base_url', 'https://api.yoursrs.com')) ?? 'https://api.yoursrs.com', '/');
        $rtrTimeout  = config('domain-checker.timeouts.realtime_register', 5);
        $rdapTimeout = config('domain-checker.timeouts.rdap', 5);

        // Collect RDAP server URLs up front (cached in TldRepository)
        $rdapServers = [];
        foreach ($tlds as $tld) {
            $server = $this->tldRepository->findRdapServer($tld);
            if ($server) {
                $rdapServers[$tld] = $server;
            }
        }

        // Single pool: all RTR + all RDAP requests fire simultaneously
        try {
            $responses = Http::pool(function ($pool) use ($domain, $tlds, $apiKey, $baseUrl, $rtrTimeout, $rdapServers, $rdapTimeout) {
                foreach ($tlds as $tld) {
                    // Realtime Register request
                    $pool->as("rtr_{$tld}")
                        ->timeout($rtrTimeout)
                        ->withHeader('Authorization', "ApiKey {$apiKey}")
                        ->acceptJson()
                        ->get("{$baseUrl}/v2/domains/{$domain}.{$tld}/check");

                    // RDAP request (only when a server is known)
                    if (isset($rdapServers[$tld])) {
                        $pool->as("rdap_{$tld}")
                            ->timeout($rdapTimeout)
                            ->withoutVerifying()
                            ->get("{$rdapServers[$tld]}/domain/{$domain}.{$tld}");
                    }
                }
            });
        } catch (\Exception $e) {
            Log::debug('RTR+RDAP pool failed', ['error' => $e->getMessage()]);
            $responses = [];
        }

        $results = [];
        $whoisFallback = [];

        foreach ($tlds as $tld) {
            // 1. Try RTR
            $rtrResponse = $responses["rtr_{$tld}"] ?? null;
            $rtrResult   = $this->parseRtrResponse($rtrResponse, $domain, $tld);

            if ($rtrResult !== null) {
                $results[$tld] = $rtrResult;
                continue;
            }

            // 2. Try RDAP
            $rdapResponse = $responses["rdap_{$tld}"] ?? null;
            $rdapResult   = $this->parseRdapResponse($rdapResponse);

            if ($rdapResult !== null) {
                $results[$tld] = $rdapResult;
                continue;
            }

            // 3. WHOIS fallback (collected for sequential processing)
            $whoisFallback[] = $tld;
        }

        foreach ($whoisFallback as $tld) {
            $results[$tld] = $this->whois->check($domain, $tld);
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

    /** Parse a Realtime Register API response. Returns null on any error. */
    private function parseRtrResponse(mixed $response, string $domain, string $tld): ?string
    {
        if ($response === null || $response instanceof \Throwable) {
            return null;
        }

        if (! $response->successful()) {
            Log::debug('RTR check non-ok', [
                'domain' => "{$domain}.{$tld}",
                'status' => $response->status(),
            ]);

            return null;
        }

        $data = $response->json();

        if (! isset($data['available'])) {
            return null;
        }

        return $data['available'] ? 'available' : 'taken';
    }

    /** Parse an RDAP response. Returns null when RDAP is unsupported or errored. */
    private function parseRdapResponse(mixed $response): ?string
    {
        if ($response === null || $response instanceof \Throwable) {
            return null;
        }

        if ($response->status() === 404) {
            return 'available';
        }

        if ($response->status() === 200) {
            return 'taken';
        }

        return null;
    }
}
