<?php

namespace Tests\Unit;

use App\Services\PublicNetworkGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class PublicNetworkGuardTest extends TestCase
{
    public function test_private_and_local_targets_are_rejected(): void
    {
        $guard = new PublicNetworkGuard;

        $this->assertNull($guard->inspectHttpUrl('http://127.0.0.1'));
        $this->assertNull($guard->inspectHttpUrl('http://10.0.0.1'));
        $this->assertNull($guard->inspectHttpUrl('http://localhost'));
        $this->assertNull($guard->inspectHttpUrl('http://example.local'));
    }

    /**
     * PHP's NO_PRIV_RANGE|NO_RES_RANGE filter passes all of these. 100.64/10 is
     * the one with teeth: that is where Tailscale and carrier-grade NAT live,
     * so a public visitor could otherwise aim the tools at our own tailnet.
     */
    #[DataProvider('reservedAddresses')]
    public function test_reserved_ranges_php_considers_public_are_rejected(string $ip): void
    {
        $this->assertFalse($this->isPublicIp($ip), "{$ip} must not count as public");
    }

    public static function reservedAddresses(): array
    {
        return [
            'CGNAT / Tailscale' => ['100.64.0.1'],
            'multicast' => ['224.0.0.1'],
            'IETF protocol assignments' => ['192.0.0.1'],
            '6to4 relay anycast' => ['192.88.99.1'],
            'benchmarking' => ['198.18.0.1'],
            'TEST-NET-3' => ['203.0.113.5'],
            'NAT64' => ['64:ff9b::808:808'],
            '6to4' => ['2002::1'],
        ];
    }

    #[DataProvider('publicAddresses')]
    public function test_real_public_addresses_still_pass(string $ip): void
    {
        $this->assertTrue($this->isPublicIp($ip), "{$ip} must count as public");
    }

    public static function publicAddresses(): array
    {
        return [
            ['8.8.8.8'],
            ['1.1.1.1'],
            ['2606:4700::1111'],
        ];
    }

    private function isPublicIp(string $ip): bool
    {
        $method = new ReflectionMethod(PublicNetworkGuard::class, 'isPublicIp');

        return (bool) $method->invoke(new PublicNetworkGuard, $ip);
    }

    public function test_curl_resolve_entries_are_one_entry_with_bracketed_ipv6(): void
    {
        $guard = new PublicNetworkGuard;

        $entries = $guard->curlResolveEntries('example.com', 443, [
            '93.184.216.34',
            '2606:2800:220:1:248:1893:25c8:1946',
        ]);

        // libcurl keeps only the last CURLOPT_RESOLVE entry per host:port, so all
        // addresses have to travel in a single comma-separated entry, and IPv6
        // addresses have to be bracketed or curl refuses to connect at all.
        $this->assertSame(
            ['example.com:443:93.184.216.34,[2606:2800:220:1:248:1893:25c8:1946]'],
            $entries,
        );
    }

    public function test_curl_resolve_entries_are_empty_without_addresses(): void
    {
        $guard = new PublicNetworkGuard;

        $this->assertSame([], $guard->curlResolveEntries('example.com', 443, []));
    }

    public function test_non_http_schemes_and_non_standard_ports_are_rejected(): void
    {
        $guard = new PublicNetworkGuard;

        $this->assertNull($guard->inspectHttpUrl('file:///etc/passwd'));
        $this->assertNull($guard->inspectHttpUrl('http://93.184.216.34:8080'));
    }
}
