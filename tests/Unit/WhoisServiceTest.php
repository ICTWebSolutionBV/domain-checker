<?php

namespace Tests\Unit;

use App\Services\WhoisService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class WhoisServiceTest extends TestCase
{
    public function test_not_available_phrase_does_not_mark_domain_available(): void
    {
        $this->assertSame('taken', $this->parseAvailability(
            "Domain Name: EXAMPLE.COM\nRegistrar: Example Registrar\nThe domain is not available.\n",
        ));
    }

    public function test_no_match_response_marks_domain_available(): void
    {
        $this->assertSame('available', $this->parseAvailability('No match for "EXAMPLE.TEST"'));
    }

    private function parseAvailability(string $response): string
    {
        $method = new ReflectionMethod(WhoisService::class, 'parseAvailability');
        $method->setAccessible(true);

        return $method->invoke(new WhoisService, $response);
    }
}
