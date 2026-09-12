<?php

namespace Tests\Feature;

use App\Services\RealtimeRegisterService;
use ReflectionProperty;
use Tests\TestCase;

/**
 * The IsProxy exchange, driven over a socket pair.
 *
 * A test must never reach a real registry, so the service's persistent socket
 * is replaced with one end of a stream_socket_pair() and the test plays the
 * server on the other end. That is enough to exercise the framing, the
 * termination condition and the write path, which is where the stalls were.
 */
class RealtimeRegisterPipelineTest extends TestCase
{
    /** @var array<resource> */
    private array $sockets = [];

    protected function setUp(): void
    {
        parent::setUp();

        // isConfigured() reads the setting, falling back to config.
        config([
            'domain-checker.realtime_register.api_key' => 'test-key',
            'domain-checker.realtime_register_read_timeout' => 1,
            'domain-checker.realtime_register_budget' => 5,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            if (is_resource($socket)) {
                @fclose($socket);
            }
        }

        $this->sockets = [];

        parent::tearDown();
    }

    public function test_duplicate_tlds_do_not_stall_the_pipeline(): void
    {
        [$service, $peer] = $this->connectedService();

        // One command is sent for two identical TLDs, so one reply is all the
        // server will ever send. Counting to two used to park the request in
        // fgets() until the 90 s socket timeout.
        fwrite($peer, "example.com available\r\n");

        $results = [];
        $start = microtime(true);

        $fallback = $service->pipelineStream('example', ['com', 'com'], function (string $tld, string $status) use (&$results): void {
            $results[] = [$tld, $status];
        });

        $this->assertLessThan(2.0, microtime(true) - $start, 'the pipeline blocked instead of finishing on its pending set');
        $this->assertSame([['com', 'available']], $results, 'com should be reported exactly once');
        $this->assertSame([], $fallback);
    }

    public function test_unanswered_domains_come_back_as_fallback_without_waiting_for_the_socket_timeout(): void
    {
        [$service, $peer] = $this->connectedService();

        fwrite($peer, "example.com available\r\n");

        $results = [];
        $start = microtime(true);

        $fallback = $service->pipelineStream('example', ['com', 'net', 'org'], function (string $tld, string $status) use (&$results): void {
            $results[] = $tld;
        });

        $elapsed = microtime(true) - $start;

        $this->assertLessThan(5.0, $elapsed, 'the unanswered TLDs waited on the socket timeout');
        $this->assertSame(['com'], $results);
        $this->assertEqualsCanonicalizing(['net', 'org'], $fallback);
    }

    public function test_a_reply_whose_framing_is_not_recognised_does_not_stall_the_stream(): void
    {
        [$service, $peer] = $this->connectedService();

        // A status-code prefix does not match the response pattern, so this
        // reply can never resolve its TLD. It must expire, not block.
        fwrite($peer, "210 example.com available\r\n");
        fwrite($peer, "example.net not available\r\n");

        $results = [];
        $start = microtime(true);

        $fallback = $service->pipelineStream('example', ['com', 'net'], function (string $tld, string $status) use (&$results): void {
            $results[$tld] = $status;
        });

        $this->assertLessThan(5.0, microtime(true) - $start);
        $this->assertSame(['net' => 'taken'], $results);
        $this->assertSame(['com'], $fallback);
    }

    public function test_a_dead_socket_falls_back_instead_of_throwing(): void
    {
        [$service, $peer] = $this->connectedService();

        // The peer went away between the feof() probe and the first write --
        // an idle connection reaped, or the server restarted. The unchecked
        // fwrite() raised a broken-pipe warning, which Laravel rethrows as an
        // ErrorException and which then truncated the SSE stream.
        fclose($peer);

        $results = [];

        $fallback = $service->pipelineStream('example', ['com', 'net'], function (string $tld, string $status) use (&$results): void {
            $results[$tld] = $status;
        });

        $this->assertSame([], $results);
        $this->assertEqualsCanonicalizing(['com', 'net'], $fallback);
    }

    public function test_a_large_burst_terminates_even_when_the_peer_never_reads(): void
    {
        [$service] = $this->connectedService();

        $tlds = [];
        for ($i = 0; $i < 1500; $i++) {
            $tlds[] = 'tld'.$i;
        }

        $start = microtime(true);

        // The peer is deliberately never drained. The old burst wrote every
        // command before reading anything, which blocks once the send buffer
        // and the peer's receive window are full.
        $fallback = $service->pipelineStream('example', $tlds, fn () => null);

        $this->assertLessThan(15.0, microtime(true) - $start, 'the write burst blocked');
        $this->assertCount(1500, $fallback);
    }

    public function test_the_whole_path_is_skipped_when_no_api_key_is_configured(): void
    {
        config(['domain-checker.realtime_register.api_key' => '']);

        $service = new RealtimeRegisterService;

        $this->assertFalse($service->isConfigured());

        // No socket is ever opened, and every TLD is handed straight back.
        $this->assertSame(['com', 'nl'], $service->pipelineStream('example', ['com', 'nl'], fn () => null));
    }

    /**
     * A service whose persistent socket is one end of a socket pair, already
     * past the login handshake.
     *
     * @return array{0: RealtimeRegisterService, 1: resource}
     */
    private function connectedService(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $this->assertNotFalse($pair, 'socket pairs are unavailable on this platform');

        [$client, $peer] = $pair;
        $this->sockets[] = $client;
        $this->sockets[] = $peer;

        $service = new RealtimeRegisterService;

        $socket = new ReflectionProperty($service, 'socket');
        $socket->setValue($service, $client);

        $loggedIn = new ReflectionProperty($service, 'loggedIn');
        $loggedIn->setValue($service, true);

        return [$service, $peer];
    }
}
