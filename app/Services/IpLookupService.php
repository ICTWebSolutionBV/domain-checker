<?php

namespace App\Services;

use App\Models\IpLookup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class IpLookupService
{
    /** Cache TTL for a single IP's lookup result. */
    private const CACHE_TTL_SECONDS = 3600;

    /**
     * Resolve a user-supplied host or IP to a public IPv4/IPv6 address.
     *
     * Returns ['ip' => string, 'hostname' => ?string] or null on failure.
     */
    public function resolve(string $input): ?array
    {
        $input = trim($input);

        if ($input === '') {
            return null;
        }

        // Strip scheme + path if user pasted a URL.
        if (preg_match('#^https?://#i', $input)) {
            $parts = parse_url($input);
            $input = $parts['host'] ?? $input;
        }

        // Already an IP?
        if (filter_var($input, FILTER_VALIDATE_IP)) {
            $hostname = @gethostbyaddr($input);

            return [
                'ip'       => $input,
                'hostname' => ($hostname && $hostname !== $input) ? $hostname : null,
            ];
        }

        // Hostname — resolve to A record.
        $ip = @gethostbyname($input);
        if ($ip === $input || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }

        return [
            'ip'       => $ip,
            'hostname' => $input,
        ];
    }

    /**
     * Look up enriched information for an IP using ip-api.com.
     *
     * Results are cached for 1 hour, and each lookup is recorded to the
     * ip_lookups table for history.
     */
    public function lookup(string $ip, ?string $hostname = null): ?array
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }

        // Reject private/reserved ranges.
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return [
                'ip'       => $ip,
                'hostname' => $hostname,
                'private'  => true,
                'message'  => 'This IP is in a private or reserved range and cannot be geolocated.',
            ];
        }

        $cacheKey = "iplookup:{$ip}";

        $data = Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($ip, $hostname) {
            return $this->fetchFromIpApi($ip, $hostname);
        });

        if ($data === null) {
            Cache::forget($cacheKey);

            return null;
        }

        // Record history row (one per lookup, independent of cache hits).
        $this->recordHistory($ip, $data);

        return $data;
    }

    /**
     * Most recent distinct IPs looked up, newest first.
     *
     * @return array<int, array{ip: string, data: array, looked_up_at: string}>
     */
    public function recentHistory(int $limit = 5): array
    {
        // Get the latest row per IP, then take the newest N distinct IPs.
        $rows = IpLookup::query()
            ->orderByDesc('looked_up_at')
            ->limit($limit * 4)
            ->get();

        $seen = [];
        $out  = [];

        foreach ($rows as $row) {
            if (isset($seen[$row->ip])) {
                continue;
            }
            $seen[$row->ip] = true;

            $out[] = [
                'ip'           => $row->ip,
                'data'         => $row->data,
                'looked_up_at' => optional($row->looked_up_at)->toIso8601String(),
            ];

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    private function fetchFromIpApi(string $ip, ?string $hostname): ?array
    {
        try {
            $response = Http::timeout(6)->get("http://ip-api.com/json/{$ip}", [
                // All fields we care about, bit-packed per ip-api.com docs.
                'fields' => 'status,message,continent,continentCode,country,countryCode,region,regionName,city,district,zip,lat,lon,timezone,offset,currency,isp,org,as,asname,reverse,mobile,proxy,hosting,query',
            ]);

            if (! $response->successful()) {
                return null;
            }

            $data = $response->json();

            if (($data['status'] ?? '') !== 'success') {
                return null;
            }

            return [
                'ip'             => $data['query'] ?? $ip,
                'hostname'       => $hostname ?: ($data['reverse'] ?? null),
                'continent'      => $data['continent'] ?? null,
                'continent_code' => $data['continentCode'] ?? null,
                'country'        => $data['country'] ?? null,
                'country_code'   => $data['countryCode'] ?? null,
                'region'         => $data['region'] ?? null,
                'region_name'    => $data['regionName'] ?? null,
                'city'           => $data['city'] ?? null,
                'district'       => $data['district'] ?: null,
                'zip'            => $data['zip'] ?: null,
                'lat'            => $data['lat'] ?? null,
                'lon'            => $data['lon'] ?? null,
                'timezone'       => $data['timezone'] ?? null,
                'utc_offset'     => $data['offset'] ?? null,
                'currency'       => $data['currency'] ?? null,
                'isp'            => $data['isp'] ?? null,
                'org'            => $data['org'] ?? null,
                'as'             => $data['as'] ?? null,
                'as_name'        => $data['asname'] ?? null,
                'reverse_dns'    => $data['reverse'] ?: null,
                'mobile'         => (bool) ($data['mobile'] ?? false),
                'proxy'          => (bool) ($data['proxy'] ?? false),
                'hosting'        => (bool) ($data['hosting'] ?? false),
                'fetched_at'     => now()->toIso8601String(),
            ];
        } catch (\Throwable $e) {
            Log::warning("IP lookup failed for {$ip}: {$e->getMessage()}");

            return null;
        }
    }

    private function recordHistory(string $ip, array $data): void
    {
        try {
            IpLookup::create([
                'ip'           => $ip,
                'data'         => $data,
                'looked_up_at' => Carbon::now(),
            ]);

            // Keep the table small — prune anything older than 30 days.
            IpLookup::query()
                ->where('looked_up_at', '<', Carbon::now()->subDays(30))
                ->delete();
        } catch (\Throwable $e) {
            Log::warning("Failed to record IP lookup history for {$ip}: {$e->getMessage()}");
        }
    }
}
