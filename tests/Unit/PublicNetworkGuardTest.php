<?php

namespace Tests\Unit;

use App\Services\PublicNetworkGuard;
use PHPUnit\Framework\TestCase;

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
