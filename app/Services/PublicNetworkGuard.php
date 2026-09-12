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
     * Build a single CURLOPT_RESOLVE entry pinning the host to the addresses we
     * already vetted. It must be ONE entry with a comma-separated address list:
     * libcurl keeps only the last entry per host:port, so one entry per IP would
     * pin us to whichever address happened to come last (usually an AAAA record,
     * unreachable on an IPv4-only host). IPv6 addresses must be bracketed or the
     * entry is malformed and curl refuses to connect at all.
     *
     * @param  list<string>  $ips
     * @return list<string>
     */
    public function curlResolveEntries(string $host, int $port, array $ips): array
    {
        if ($ips === []) {
            return [];
        }

        $addresses = array_map(
            fn (string $ip) => $this->formatConnectHost($ip),
            $ips,
        );

        return ["{$host}:{$port}:".implode(',', $addresses)];
    }

    public function formatConnectHost(string $ip): string
    {
        return str_contains($ip, ':') ? "[{$ip}]" : $ip;
    }

    /**
     * Ranges PHP's own NO_PRIV_RANGE|NO_RES_RANGE filter lets through.
     *
     * The one that matters operationally is 100.64.0.0/10: shared address
     * space, which is where carrier-grade NAT and Tailscale live, so without
     * this list a public visitor could aim the network tools at hosts on our
     * own tailnet. The rest are protocol assignments, benchmarking and
     * documentation space -- nothing a domain tool has business connecting to.
     */
    private const BLOCKED_RANGES = [
        '0.0.0.0/8',          // this network
        '100.64.0.0/10',      // shared address space (CGNAT, Tailscale)
        '192.0.0.0/24',       // IETF protocol assignments
        '192.0.2.0/24',       // TEST-NET-1
        '192.88.99.0/24',     // 6to4 relay anycast
        '198.18.0.0/15',      // benchmarking
        '198.51.100.0/24',    // TEST-NET-2
        '203.0.113.0/24',     // TEST-NET-3
        '224.0.0.0/4',        // multicast
        '240.0.0.0/4',        // reserved
        '64:ff9b::/96',       // NAT64
        '100::/64',           // discard-only
        '2001:db8::/32',      // documentation
        '2002::/16',          // 6to4
        'ff00::/8',           // multicast
    ];

    private function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        foreach (self::BLOCKED_RANGES as $range) {
            if ($this->ipInRange($ip, $range)) {
                return false;
            }
        }

        return true;
    }

    private function ipInRange(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);

        $address = @inet_pton($ip);
        $network = @inet_pton($subnet);

        // Different families (4 vs 16 bytes) simply do not overlap.
        if ($address === false || $network === false || strlen($address) !== strlen($network)) {
            return false;
        }

        $wholeBytes = intdiv((int) $bits, 8);
        $spareBits = (int) $bits % 8;

        if ($wholeBytes > 0 && strncmp($address, $network, $wholeBytes) !== 0) {
            return false;
        }

        if ($spareBits === 0) {
            return true;
        }

        $mask = chr((0xFF << (8 - $spareBits)) & 0xFF);

        return ($address[$wholeBytes] & $mask) === ($network[$wholeBytes] & $mask);
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
