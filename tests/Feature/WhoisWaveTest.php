<?php

namespace Tests\Feature;

use App\Services\WhoisService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The concurrent WHOIS wave, against local sockets rather than a registry.
 *
 * Two kinds of local server are used. A listening socket that is never
 * accepted still completes the TCP handshake from its backlog, which is exactly
 * the "registry accepts and then says nothing" case that used to cost the full
 * read timeout per TLD, one after another. For the cases that need an actual
 * answer, a short-lived child process plays the registry.
 */
class WhoisWaveTest extends TestCase
{
    /** @var resource|null */
    private mixed $listener = null;

    /** @var resource|null */
    private mixed $process = null;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    protected function tearDown(): void
    {
        if (is_resource($this->listener)) {
            @fclose($this->listener);
        }

        if (is_resource($this->process)) {
            @proc_terminate($this->process);
            @proc_close($this->process);
        }

        $this->listener = null;
        $this->process = null;

        parent::tearDown();
    }

    public function test_silent_registries_expire_together_rather_than_one_after_another(): void
    {
        $port = $this->listenWithoutAccepting();

        config([
            'domain-checker.whois_port' => $port,
            'domain-checker.timeouts.whois' => 1,
            'domain-checker.concurrency.whois' => 24,
            'domain-checker.whois_wave_budget' => 20,
        ]);

        $tlds = [];
        for ($i = 0; $i < 12; $i++) {
            $tlds[] = 'tld'.$i;
            Cache::put("whois_server:tld{$i}", '127.0.0.1', 3600);
        }

        $results = [];
        $start = microtime(true);

        app(WhoisService::class)->streamCheck('example', $tlds, function (string $tld, string $status) use (&$results): void {
            $results[$tld] = $status;
        });

        $elapsed = microtime(true) - $start;

        // Serially this is 12 x the 1 s idle timeout. Concurrently it is one.
        $this->assertLessThan(5.0, $elapsed, "the wave took {$elapsed}s, which is serial, not concurrent");
        $this->assertCount(12, $results);
        $this->assertSame(['unknown'], array_values(array_unique($results)));
    }

    public function test_the_wave_runs_beyond_its_concurrency_ceiling_in_successive_slabs(): void
    {
        $port = $this->listenWithoutAccepting();

        config([
            'domain-checker.whois_port' => $port,
            'domain-checker.timeouts.whois' => 1,
            // Deliberately below the number of TLDs: the rest must be topped up
            // as sockets finish, not dropped.
            'domain-checker.concurrency.whois' => 4,
            'domain-checker.whois_wave_budget' => 20,
        ]);

        $tlds = [];
        for ($i = 0; $i < 12; $i++) {
            $tlds[] = 'tld'.$i;
            Cache::put("whois_server:tld{$i}", '127.0.0.1', 3600);
        }

        $results = [];

        app(WhoisService::class)->streamCheck('example', $tlds, function (string $tld, string $status) use (&$results): void {
            $results[$tld] = $status;
        });

        $this->assertCount(12, $results, 'TLDs queued behind the concurrency ceiling were never reported');
    }

    public function test_every_tld_is_reported_once_the_wave_budget_is_spent(): void
    {
        $port = $this->listenWithoutAccepting();

        config([
            'domain-checker.whois_port' => $port,
            'domain-checker.timeouts.whois' => 30,
            'domain-checker.concurrency.whois' => 2,
            // The budget expires long before the read timeouts do.
            'domain-checker.whois_wave_budget' => 1,
        ]);

        $tlds = ['tld0', 'tld1', 'tld2', 'tld3'];
        foreach ($tlds as $tld) {
            Cache::put("whois_server:{$tld}", '127.0.0.1', 3600);
        }

        $results = [];
        $start = microtime(true);

        app(WhoisService::class)->streamCheck('example', $tlds, function (string $tld, string $status) use (&$results): void {
            $results[$tld] = $status;
        });

        $elapsed = microtime(true) - $start;

        // The old loop had no wall-clock bound at all.
        $this->assertLessThan(10.0, $elapsed, "the wave ignored its budget and ran for {$elapsed}s");
        $this->assertCount(4, $results, 'a TLD the budget cut off was never reported');
        $this->assertSame(['unknown'], array_values(array_unique($results)));
    }

    public function test_a_tld_with_no_port_43_service_is_reported_without_opening_a_socket(): void
    {
        // An empty cached server is IANA saying there is no WHOIS service --
        // .uk since Nominet moved to RDAP, for instance.
        Cache::put('whois_server:uk', '', 3600);
        Cache::put('whois_server:zz', '', 3600);

        config(['domain-checker.whois_port' => 1, 'domain-checker.timeouts.whois' => 5]);

        $results = [];
        $start = microtime(true);

        app(WhoisService::class)->streamCheck('example', ['uk', 'zz'], function (string $tld, string $status) use (&$results): void {
            $results[$tld] = $status;
        });

        $this->assertLessThan(1.0, microtime(true) - $start);
        $this->assertSame(['uk' => 'unknown', 'zz' => 'unknown'], $results);
    }

    public function test_the_wave_deduplicates_the_tlds_it_is_given(): void
    {
        Cache::put('whois_server:zz', '', 3600);

        config(['domain-checker.whois_port' => 1]);

        $seen = [];

        app(WhoisService::class)->streamCheck('example', ['zz', 'zz', 'zz'], function (string $tld) use (&$seen): void {
            $seen[] = $tld;
        });

        $this->assertSame(['zz'], $seen);
    }

    public function test_a_registry_answer_is_read_and_parsed(): void
    {
        // The DENIC shape: an echoed domain field plus an explicit free status.
        $port = $this->registryAnswering("Domain: example.tld0\r\nStatus: free\r\n");

        config([
            'domain-checker.whois_port' => $port,
            'domain-checker.timeouts.whois' => 3,
            'domain-checker.whois_wave_budget' => 20,
        ]);

        Cache::put('whois_server:tld0', '127.0.0.1', 3600);
        Cache::put('whois_server:tld1', '127.0.0.1', 3600);

        $results = [];

        app(WhoisService::class)->streamCheck('example', ['tld0', 'tld1'], function (string $tld, string $status) use (&$results): void {
            $results[$tld] = $status;
        });

        $this->assertSame('available', $results['tld0'] ?? null);
        $this->assertSame('available', $results['tld1'] ?? null, 'the second concurrent socket did not get its answer');
    }

    public function test_a_response_larger_than_the_cap_is_truncated_rather_than_read_forever(): void
    {
        $port = $this->registryAnswering(str_repeat('x', 40_000)."\r\nNo match\r\n");

        config([
            'domain-checker.whois_port' => $port,
            'domain-checker.timeouts.whois' => 3,
            'domain-checker.whois_max_response' => 1024,
            'domain-checker.whois_wave_budget' => 20,
        ]);

        Cache::put('whois_server:tld0', '127.0.0.1', 3600);

        $results = [];

        app(WhoisService::class)->streamCheck('example', ['tld0'], function (string $tld, string $status) use (&$results): void {
            $results[$tld] = $status;
        });

        // Cut off after 1 KB of padding, so the "No match" never arrives and
        // the honest answer is 'unknown' -- but it does answer.
        $this->assertSame(['tld0' => 'unknown'], $results);
    }

    /**
     * A listener that is bound but never accepted: connections succeed from the
     * backlog, and no byte ever arrives.
     */
    private function listenWithoutAccepting(): int
    {
        $listener = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($listener, "could not bind a local listener: {$errstr}");

        $this->listener = $listener;

        return $this->portOf($listener);
    }

    /**
     * A child process that answers every connection with $reply and closes.
     */
    private function registryAnswering(string $reply): int
    {
        $script = <<<'PHP'
            $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
            if (! $listener) { exit(1); }
            $name = stream_socket_get_name($listener, false);
            echo substr($name, strrpos($name, ':') + 1), "\n";
            flush();
            $reply = getenv('WHOIS_TEST_REPLY');
            for ($i = 0; $i < 16; $i++) {
                $client = @stream_socket_accept($listener, 10);
                if ($client === false) { break; }
                @stream_get_line($client, 1024, "\n");
                @fwrite($client, $reply);
                @fclose($client);
            }
            PHP;

        $process = proc_open(
            [PHP_BINARY, '-r', $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['WHOIS_TEST_REPLY' => $reply],
        );

        $this->assertIsResource($process, 'could not start the stand-in registry');
        $this->process = $process;

        $port = (int) trim((string) fgets($pipes[1]));
        $this->assertGreaterThan(0, $port, 'the stand-in registry never reported a port');

        return $port;
    }

    /**
     * @param  resource  $socket
     */
    private function portOf(mixed $socket): int
    {
        $name = (string) stream_socket_get_name($socket, false);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
