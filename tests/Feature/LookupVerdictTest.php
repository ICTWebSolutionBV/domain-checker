<?php

namespace Tests\Feature;

use App\Services\DomainAvailabilityService;
use App\Services\RdapService;
use App\Services\RealtimeRegisterService;
use App\Services\WhoisService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class LookupVerdictTest extends TestCase
{
    public function test_an_unknown_verdict_gets_the_short_ttl_not_the_result_ttl(): void
    {
        config(['domain-checker.cache.result_ttl' => 900, 'domain-checker.cache.unknown_ttl' => 60]);

        $this->mock(RealtimeRegisterService::class, fn ($m) => $m->shouldReceive('isConfigured')->andReturnFalse());
        // RDAP has no server for it, so it comes back as needing WHOIS.
        $this->mock(RdapService::class, fn ($m) => $m->shouldReceive('streamCheck')->andReturn(['zz']));
        $this->mock(WhoisService::class, fn ($m) => $m->shouldReceive('streamCheck')->andReturnUsing(
            fn (string $domain, array $tlds, callable $onResult) => $onResult('zz', 'unknown'),
        ));

        Cache::shouldReceive('many')->andReturn(['domain_check_example_zz' => null]);
        // The point of the fix: a non-answer must not sit in the cache for the
        // full 15 minutes, or a blip during a client call lasts the whole call.
        Cache::shouldReceive('putMany')->once()->with(['domain_check_example_zz' => 'unknown'], 60);

        app(DomainAvailabilityService::class)->streamCheck('example', ['zz'], fn () => null);
    }

    public function test_a_real_verdict_gets_the_full_result_ttl(): void
    {
        config(['domain-checker.cache.result_ttl' => 900, 'domain-checker.cache.unknown_ttl' => 60]);

        $this->mock(RealtimeRegisterService::class, fn ($m) => $m->shouldReceive('isConfigured')->andReturnFalse());
        $this->mock(RdapService::class, fn ($m) => $m->shouldReceive('streamCheck')->andReturnUsing(
            function (string $domain, array $tlds, callable $onResult): array {
                $onResult('nl', 'available');

                return [];
            },
        ));

        Cache::shouldReceive('many')->andReturn(['domain_check_example_nl' => null]);
        Cache::shouldReceive('putMany')->once()->with(['domain_check_example_nl' => 'available'], 900);

        app(DomainAvailabilityService::class)->streamCheck('example', ['nl'], fn () => null);
    }

    public function test_a_unicode_domain_reaches_the_lookup_as_punycode(): void
    {
        $seen = null;

        // A plain closure, not an arrow function: arrow functions capture by
        // value, so a by-reference $seen inside one never reaches this scope.
        $this->mock(
            DomainAvailabilityService::class,
            function ($m) use (&$seen) {
                $m->shouldReceive('streamCheck')->andReturnUsing(
                    function (string $domain, array $tlds, callable $onResult) use (&$seen): void {
                        $seen = $domain;
                        $onResult($tlds[0], 'available');
                    },
                );
            },
        );

        $this->post('/check', ['domain' => 'münchen', 'tlds' => 'de'])->assertOk()->streamedContent();

        $this->assertSame('xn--mnchen-3ya', $seen);
    }
}
