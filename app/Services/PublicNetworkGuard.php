<?php

namespace App\Services;

class PublicNetworkGuard
{
    private const BLOCKED_SUFFIXES = [
        '.localhost',
        '.local',
        '.internal',
        '.test',
        '.invalid',
    ];

    /**
     * @return array{url: string, host: string, port: int, ips: list<string>, curl_resolve: list<string>}|null
     */
    public function inspectHttpUrl(string $input): ?array
    {
        $url = $this->normalizeHttpUrl($input);

        if ($url === null) {
            return null;
        }

        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $port = (int) ($parts['port'] ?? ($scheme === 'http' ? 80 : 443));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        if (! in_array($port, [80, 443], true)) {
            return null;
        }

        $ips = $this->resolvePublicIps($host);

        if (empty($ips)) {
            return null;
        }

        return [
            'url' => $url,
            'host' => $host,
            'port' => $port,
            'ips' => $ips,
            'curl_resolve' => $this->curlResolveEntries($host, $port, $ips),
        ];
    }

    public function normalizeHttpUrl(string $input): ?string
    {
        $input = trim($input);

        if ($input === '') {
            return null;
        }

        if (! preg_match('#^https?://#i', $input)) {
            $input = 'https://'.$input;
        }

        $parts = parse_url($input);

        if ($parts === false || empty($parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $host = strtolower(rtrim((string) $parts['host'], '.'));
        $urlHost = str_contains($host, ':') && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            ? "[{$host}]"
            : $host;
        $port = isset($parts['port']) ? ':'.((int) $parts['port']) : '';
        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return "{$scheme}://{$urlHost}{$port}{$path}{$query}";
    }

    /**
     * @return list<string>
     */
    public function resolvePublicIps(string $host): array
    {
        $host = strtolower(rtrim($host, '.'));

        if ($host === '' || $this->isBlockedHostname($host)) {
            return [];
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->isPublicIp($host) ? [$host] : [];
        }

        if (! $this->isValidHostname($host)) {
            return [];
        }

        $ips = [];

        $aRecords = @dns_get_record($host, DNS_A) ?: [];
        foreach ($aRecords as $record) {
            if (! empty($record['ip'])) {
                $ips[] = $record['ip'];
            }
        }

        $aaaaRecords = @dns_get_record($host, DNS_AAAA) ?: [];
        foreach ($aaaaRecords as $record) {
            if (! empty($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        $ips = array_values(array_unique(array_filter($ips)));

        foreach ($ips as $ip) {
            if (! $this->isPublicIp($ip)) {
                return [];
            }
        }

        return $ips;
    }

    /**
     * @param  list<string>  $ips
     * @return list<string>
     */
    public function curlResolveEntries(string $host, int $port, array $ips): array
    {
        return array_map(
            fn (string $ip) => "{$host}:{$port}:{$ip}",
            $ips,
        );
    }

    public function formatConnectHost(string $ip): string
    {
        return str_contains($ip, ':') ? "[{$ip}]" : $ip;
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private function isValidHostname(string $host): bool
    {
        return (bool) preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $host);
    }

    private function isBlockedHostname(string $host): bool
    {
        if ($host === 'localhost') {
            return true;
        }

        foreach (self::BLOCKED_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
