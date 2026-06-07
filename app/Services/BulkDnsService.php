<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class BulkDnsService
{
    private const CACHE_TTL = 300;

    public function resolveIp(string $domain): ?string
    {
        $ip = @gethostbyname($domain);

        return ($ip && $ip !== $domain && filter_var($ip, FILTER_VALIDATE_IP)) ? $ip : null;
    }

    public function lookupRecords(string $domain, string $type): array
    {
        $cacheKey = "dns:{$type}:{$domain}";

        return Cache::remember($cacheKey, self::CACHE_TTL, fn () => $this->fetchRecords($domain, $type));
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
