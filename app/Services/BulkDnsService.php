<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BulkDnsService
{
    private const DNS_CACHE_TTL = 300;
    private const GEO_CACHE_TTL = 3600;
    private const GEO_WORKERS   = 4;

    public function resolveIp(string $domain): ?string
    {
        $ip = @gethostbyname($domain);

        return ($ip && $ip !== $domain && filter_var($ip, FILTER_VALIDATE_IP)) ? $ip : null;
    }

    public function lookupRecords(string $domain, string $type): array
    {
        $cacheKey = "dns:{$type}:{$domain}";

        return Cache::remember($cacheKey, self::DNS_CACHE_TTL, fn () => $this->fetchRecords($domain, $type));
    }

    /**
     * Batch-geo IPs using 4 concurrent workers via ip-api.com batch endpoint.
     * Returns map of ip => geo array.
     */
    public function lookupGeoBatch(array $ips): array
    {
        if (empty($ips)) {
            return [];
        }

        $ips = array_values(array_unique(array_filter($ips, fn ($ip) =>
            $ip && filter_var($ip, FILTER_VALIDATE_IP)
                && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
        )));

        // Split into cached vs uncached
        $result    = [];
        $uncached  = [];

        foreach ($ips as $ip) {
            $cached = Cache::get("geo:{$ip}");
            if ($cached !== null) {
                $result[$ip] = $cached;
            } else {
                $uncached[] = $ip;
            }
        }

        if (empty($uncached)) {
            return $result;
        }

        // Divide into up to GEO_WORKERS chunks and fire concurrently
        $workerCount = min(self::GEO_WORKERS, count($uncached));
        $chunks      = array_chunk($uncached, (int) ceil(count($uncached) / $workerCount));
        $fields      = 'status,query,country,countryCode,regionName,city,isp,as';

        try {
            $responses = Http::pool(function ($pool) use ($chunks, $fields) {
                return array_map(
                    fn ($chunk) => $pool->timeout(8)->post('http://ip-api.com/batch',
                        array_map(fn ($ip) => ['query' => $ip, 'fields' => $fields], $chunk)
                    ),
                    $chunks
                );
            });
        } catch (\Throwable $e) {
            Log::warning("Geo batch failed: {$e->getMessage()}");

            return $result;
        }

        foreach ($responses as $response) {
            if ($response instanceof \Throwable || ! $response->successful()) {
                continue;
            }

            foreach ($response->json() ?? [] as $item) {
                if (($item['status'] ?? '') !== 'success') {
                    continue;
                }

                $ip  = $item['query'];
                $raw = $item['as'] ?? '';
                $geo = [
                    'country'      => $item['country'] ?? null,
                    'country_code' => $item['countryCode'] ?? null,
                    'region'       => $item['regionName'] ?? null,
                    'city'         => $item['city'] ?? null,
                    'isp'          => $item['isp'] ?? null,
                    'asn'          => $raw ? (preg_match('/^AS(\d+)/', $raw, $m) ? $m[1] : $raw) : null,
                ];

                $result[$ip] = $geo;
                Cache::put("geo:{$ip}", $geo, self::GEO_CACHE_TTL);
            }
        }

        return $result;
    }

    private function fetchRecords(string $domain, string $type): array
    {
        $typeMap = [
            'MX'    => DNS_MX,
            'NS'    => DNS_NS,
            'TXT'   => DNS_TXT,
            'A'     => DNS_A,
            'AAAA'  => DNS_AAAA,
            'CNAME' => DNS_CNAME,
        ];

        $dnsType = $typeMap[$type] ?? null;
        if ($dnsType === null) {
            return [];
        }

        try {
            $records = @dns_get_record($domain, $dnsType);
            if (! $records) {
                return [];
            }

            return match ($type) {
                'MX'    => array_map(fn ($r) => ['value' => rtrim($r['target'], '.'), 'priority' => $r['pri']], $records),
                'NS'    => array_map(fn ($r) => ['value' => rtrim($r['target'], '.')], $records),
                'TXT'   => array_map(fn ($r) => ['value' => $r['txt'] ?? implode('', $r['entries'] ?? [])], $records),
                'A'     => array_map(fn ($r) => ['value' => $r['ip']], $records),
                'AAAA'  => array_map(fn ($r) => ['value' => $r['ipv6']], $records),
                'CNAME' => array_map(fn ($r) => ['value' => rtrim($r['target'], '.')], $records),
                default => [],
            };
        } catch (\Throwable $e) {
            Log::warning("DNS lookup failed for {$domain} ({$type}): {$e->getMessage()}");

            return [];
        }
    }
}
