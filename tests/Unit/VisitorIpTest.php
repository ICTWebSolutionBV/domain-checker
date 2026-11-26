<?php

namespace Tests\Unit;

use App\Support\VisitorIp;
use PHPUnit\Framework\TestCase;

class VisitorIpTest extends TestCase
{
    public function test_ipv4_is_its_own_bucket(): void
    {
        $this->assertSame('203.0.113.7', VisitorIp::bucket('203.0.113.7'));
    }

    public function test_ipv6_addresses_in_one_64_share_a_bucket(): void
    {
        $this->assertSame('2001:db8::/64', VisitorIp::bucket('2001:db8::1'));
        $this->assertSame('2001:db8::/64', VisitorIp::bucket('2001:0db8:0000:0000:ffff:ffff:ffff:ffff'));
        $this->assertSame('2001:db8:0:1::/64', VisitorIp::bucket('2001:db8:0:1::1'));
    }

    public function test_an_ipv4_mapped_address_is_folded_to_ipv4(): void
    {
        $this->assertSame('203.0.113.7', VisitorIp::bucket('::ffff:203.0.113.7'));
        $this->assertSame('203.0.113.7', VisitorIp::bucket('::FFFF:cb00:7107'));
    }

    public function test_anything_else_has_no_bucket(): void
    {
        foreach (['', 'not-an-ip', '203.0.113.7, 198.51.100.1', '203.0.113.256', '2001:db8::/64'] as $input) {
            $this->assertNull(VisitorIp::bucket($input), $input);
        }
    }
}
