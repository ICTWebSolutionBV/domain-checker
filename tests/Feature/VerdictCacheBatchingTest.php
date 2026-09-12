<?php

namespace Tests\Feature;

use App\Services\DomainAvailabilityService;
use App\Services\RdapService;
use App\Services\RealtimeRegisterService;
use App\Services\VerdictCache;
use App\Services\WhoisService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The cache bookkeeping around a lookup.
 *
 * One Cache::get and one Cache::put per TLD is a MySQL round-trip each way with
 * the database store: a full-list run measured 1287 SELECTs (0.36 s) plus ~1.7 s
 * of upserts before a single byte went out to a registry.
 */
class VerdictCacheBatchingTest extends TestCase
{
    public function test_the_read_pass_is_a_single_batched_lookup(): void
    {
        $this->mock(RealtimeRegisterService::class, fn ($m) => $m->shouldReceive('isConfigured')->andReturnFalse());
        $this->mock(RdapService::class, fn ($m) => $m->shouldReceive('streamCheck')->andReturn([]));
        $this->mock(WhoisService::class, fn ($m) => $m->shouldReceive('streamCheck'));

        Cache::shouldReceive('many')
            ->once()
            ->with([
                'domain_check_example_com',
                'domain_check_example_net',
                'domain_check_example_nl',
            ])
            ->andReturn([
                'domain_check_example_com' => null,
                'domain_check_example_net' => null,
                'domain_check_example_nl' => null,
            ]);

        // Cache::get must not be reached at all.
        Cache::shouldReceive('get')->never();

        app(DomainAvailabilityService::class)->streamCheck('example', ['com', 'net', 'nl'], fn () => null);
    }

    public function test_cached_verdicts_are_still_emitted_before_any_live_check_runs(): void
    {
        Cache::put('domain_check_example_com', 'taken', 900);
        Cache::put('domain_check_example_nl', 'available', 900);

        $order = [];

        $this->mock(RealtimeRegisterService::class, fn ($m) => $m->shouldReceive('isConfigured')->andReturnFalse());
        $this->mock(RdapService::class, function ($m) use (&$order) {
            $m->shouldReceive('streamCheck')->andReturnUsing(
                function (string $domain, array $tlds, callable $onResult) use (&$order): array {
                    $order[] = 'live:'.implode(',', $tlds);
                    $onResult('net', 'available');

                    return [];
                },
            );
        });

        app(DomainAvailabilityService::class)->streamCheck('example', ['com', 'net', 'nl'], function (string $tld, string $status) use (&$order): void {
            $order[] = "emit:{$tld}={$status}";
        });

        $this->assertSame([
            'emit:com=taken',
            'emit:nl=available',
            'live:net',
            'emit:net=available',
        ], $order);
    }

    public function test_writes_are_grouped_by_ttl_so_a_non_answer_keeps_the_short_one(): void
    {
        config(['domain-checker.cache.result_ttl' => 900, 'domain-checker.cache.unknown_ttl' => 60]);

        $this->mock(RealtimeRegisterService::class, fn ($m) => $m->shouldReceive('isConfigured')->andReturnFalse());
        $this->mock(RdapService::class, fn ($m) => $m->shouldReceive('streamCheck')->andReturnUsing(
            function (string $domain, array $tlds, callable $onResult): array {
                $onResult('com', 'taken');
                $onResult('nl', 'available');

                return ['zz'];
            },
        ));
        $this->mock(WhoisService::class, fn ($m) => $m->shouldReceive('streamCheck')->andReturnUsing(
            fn (string $domain, array $tlds, callable $onResult) => $onResult('zz', 'unknown'),
        ));

        Cache::shouldReceive('many')->andReturn([]);

        Cache::shouldReceive('putMany')->once()->with([
            'domain_check_example_com' => 'taken',
            'domain_check_example_nl' => 'available',
        ], 900);

        Cache::shouldReceive('putMany')->once()->with([
            'domain_check_example_zz' => 'unknown',
        ], 60);

        app(DomainAvailabilityService::class)->streamCheck('example', ['com', 'nl', 'zz'], fn () => null);
    }

    public function test_the_buffer_flushes_mid_run_so_a_long_check_does_not_hold_every_result(): void
    {
        config(['domain-checker.cache.result_ttl' => 900]);

        // Two full slabs plus a remainder: three writes, not 120.
        $buffer = new VerdictCache('example', flushAt: 50);

        Cache::shouldReceive('putMany')->times(3);

        for ($i = 0; $i < 120; $i++) {
            $buffer->put('tld'.$i, 'available');
        }

        $buffer->flush();
    }

    public function test_a_flush_with_nothing_buffered_writes_nothing(): void
    {
        Cache::shouldReceive('putMany')->never();

        (new VerdictCache('example'))->flush();
    }
}
