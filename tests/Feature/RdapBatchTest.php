<?php

namespace Tests\Feature;

use App\Services\RdapService;
use App\Services\TldRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The RDAP leg, with every registry faked. No test may reach a real one.
 */
class RdapBatchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_every_tld_goes_out_in_one_batch_and_streams_back_as_it_resolves(): void
    {
        $this->bootstrapServing([
            'com' => 'https://rdap.verisign.test/com/v1',
            'net' => 'https://rdap.verisign.test/com/v1',
            'nl' => 'https://rdap.sidn.test',
        ]);

        Http::fake([
            'rdap.verisign.test/com/v1/domain/example.com' => Http::response('{}', 200),
            'rdap.verisign.test/com/v1/domain/example.net' => Http::response('', 404),
            'rdap.sidn.test/domain/example.nl' => Http::response('', 404),
        ]);

        $results = [];

        $fallback = app(RdapService::class)->streamCheck('example', ['com', 'net', 'nl'], function (string $tld, string $status) use (&$results): void {
            $results[$tld] = $status;
        });

        $this->assertSame(['taken', 'available', 'available'], [$results['com'], $results['net'], $results['nl']]);
        $this->assertSame([], $fallback);

        // One request per TLD, all from the same batch.
        Http::assertSentCount(3);
    }

    public function test_a_tld_without_an_rdap_server_is_handed_straight_to_the_fallback(): void
    {
        $this->bootstrapServing(['com' => 'https://rdap.verisign.test/com/v1']);

        Http::fake(['rdap.verisign.test/*' => Http::response('', 404)]);

        $results = [];

        $fallback = app(RdapService::class)->streamCheck('example', ['com', 'de', 'be'], function (string $tld, string $status) use (&$results): void {
            $results[$tld] = $status;
        });

        $this->assertSame(['com' => 'available'], $results);
        $this->assertSame(['de', 'be'], $fallback);
        Http::assertSentCount(1);
    }

    public function test_a_status_that_is_not_an_answer_falls_back_rather_than_guessing(): void
    {
        $this->bootstrapServing([
            'com' => 'https://rdap.verisign.test/com/v1',
            'io' => 'https://rdap.throttled.test',
        ]);

        Http::fake([
            'rdap.verisign.test/*' => Http::response('', 404),
            // A rate limit is not a verdict.
            'rdap.throttled.test/*' => Http::response('', 429),
        ]);

        $results = [];

        $fallback = app(RdapService::class)->streamCheck('example', ['com', 'io'], function (string $tld, string $status) use (&$results): void {
            $results[$tld] = $status;
        });

        $this->assertSame(['com' => 'available'], $results);
        $this->assertSame(['io'], $fallback);
    }

    public function test_a_transport_failure_for_one_tld_does_not_take_the_others_down_with_it(): void
    {
        $this->bootstrapServing([
            'com' => 'https://rdap.verisign.test/com/v1',
            'xyz' => 'https://rdap.broken.test',
        ]);

        Http::fake([
            'rdap.verisign.test/*' => Http::response('{}', 200),
            'rdap.broken.test/*' => fn () => throw new ConnectionException('connection refused'),
        ]);

        $results = [];

        $fallback = app(RdapService::class)->streamCheck('example', ['com', 'xyz'], function (string $tld, string $status) use (&$results): void {
            $results[$tld] = $status;
        });

        // Previously a throw while the pool was being assembled emptied the
        // whole chunk's response array and all ten TLDs became 'unknown'.
        $this->assertSame(['com' => 'taken'], $results);
        $this->assertSame(['xyz'], $fallback);
    }

    public function test_a_bootstrap_entry_with_an_empty_server_cannot_poison_the_batch(): void
    {
        // rtrim('', '/') used to be returned as if it were a server, producing
        // the relative URL "/domain/example.zz".
        Cache::put('rdap_bootstrap', [
            [['com'], ['https://rdap.verisign.test/com/v1']],
            [['zz'], ['']],
            [['yy'], []],
        ], 3600);

        Http::fake(['rdap.verisign.test/*' => Http::response('', 404)]);

        $results = [];

        $fallback = app(RdapService::class)->streamCheck('example', ['com', 'zz', 'yy'], function (string $tld, string $status) use (&$results): void {
            $results[$tld] = $status;
        });

        $this->assertSame(['com' => 'available'], $results);
        $this->assertSame(['zz', 'yy'], $fallback);
        Http::assertSentCount(1);
    }

    public function test_the_server_map_is_built_once_per_instance(): void
    {
        Cache::put('rdap_bootstrap', [
            [['com', 'net', 'cc'], ['https://rdap.verisign.test/com/v1']],
        ], 3600);

        $repository = app(TldRepository::class);

        $this->assertSame('https://rdap.verisign.test/com/v1', $repository->findRdapServer('com'));
        $this->assertSame('https://rdap.verisign.test/com/v1', $repository->findRdapServer('CC'));

        // Once the map is memoised the bootstrap is no longer consulted, so
        // emptying the cache underneath it changes nothing. It used to be a
        // cache read plus a 590-entry scan per TLD.
        Cache::forget('rdap_bootstrap');
        Cache::forget('rdap_server_map');

        $this->assertSame('https://rdap.verisign.test/com/v1', $repository->findRdapServer('net'));
        $this->assertNull($repository->findRdapServer('nope'));
    }

    /**
     * Seed the RDAP bootstrap cache with a tld => server mapping.
     *
     * @param  array<string, string>  $servers
     */
    private function bootstrapServing(array $servers): void
    {
        $services = [];

        foreach ($servers as $tld => $server) {
            $services[] = [[$tld], [$server]];
        }

        Cache::put('rdap_bootstrap', $services, 3600);
    }
}
