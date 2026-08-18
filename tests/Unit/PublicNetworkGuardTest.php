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

    public function test_non_http_schemes_and_non_standard_ports_are_rejected(): void
    {
        $guard = new PublicNetworkGuard;

        $this->assertNull($guard->inspectHttpUrl('file:///etc/passwd'));
        $this->assertNull($guard->inspectHttpUrl('http://93.184.216.34:8080'));
    }
}
