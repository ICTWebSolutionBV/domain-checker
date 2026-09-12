<?php

namespace Tests\Unit;

use App\Support\DomainName;
use PHPUnit\Framework\TestCase;

class DomainNameTest extends TestCase
{
    public function test_unicode_names_become_punycode(): void
    {
        $this->assertSame('xn--mnchen-3ya', DomainName::toAscii('münchen'));
        $this->assertSame('xn--caf-dma.nl', DomainName::toAscii('café.nl'));
    }

    public function test_ascii_input_is_returned_untouched(): void
    {
        $this->assertSame('example', DomainName::toAscii('example'));
        $this->assertSame('example.co.uk', DomainName::toAscii('  example.co.uk  '));
    }

    public function test_empty_input_is_safe(): void
    {
        $this->assertSame('', DomainName::toAscii(''));
    }
}
