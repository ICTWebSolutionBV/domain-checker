<?php

namespace Tests\Feature;

use App\Services\DomainAvailabilityService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class BulkCheckSubdomainTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::put('iana_tld_list', ['com', 'nl'], 60);
    }

    /**
     * Nobody can register a subdomain, and no registry holds a record of one,
     * so asking literally used to return "available" -- the most expensive
     * wrong answer this tool can give.
     */
    public function test_a_subdomain_line_is_checked_as_its_registrable_domain(): void
    {
        $asked = [];

        $this->mock(
            DomainAvailabilityService::class,
            function ($mock) use (&$asked) {
                $mock->shouldReceive('streamCheck')->andReturnUsing(
                    function (string $domain, array $tlds, callable $onResult) use (&$asked): void {
                        $asked[] = $domain.'.'.$tlds[0];
                        $onResult($tlds[0], 'taken');
                    },
                );
            },
        );

        $body = $this->post('/bulk-check', ['domains' => "blog.google.com\nexample.nl"])
            ->assertOk()
            ->streamedContent();

        $this->assertSame(['google.com', 'example.nl'], $asked);

        // The reply stays keyed on the line the client sent -- the front end
        // maps results back by that exact string -- and names what was really
        // checked when the two differ.
        $this->assertStringContainsString('"domain":"blog.google.com"', $body);
        $this->assertStringContainsString('"checked_domain":"google.com"', $body);
        $this->assertStringContainsString('"domain":"example.nl"', $body);
        $this->assertStringNotContainsString('"checked_domain":"example.nl"', $body);
    }
}
