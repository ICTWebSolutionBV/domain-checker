<?php

namespace Tests\Feature;

use App\Services\DomainAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class DomainCheckStreamTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The one invariant the front end depends on: however a check ends, the
     * stream ends with a done event. Without it the UI spins forever, which is
     * exactly what a mid-stream exception used to produce.
     */
    public function test_stream_ends_with_done_even_when_the_lookup_throws(): void
    {
        $this->mock(
            DomainAvailabilityService::class,
            fn ($mock) => $mock->shouldReceive('streamCheck')->andReturnUsing(
                function (string $domain, array $tlds, callable $onResult): void {
                    $onResult($tlds[0], 'available');

                    throw new RuntimeException('registry exploded');
                },
            ),
        );

        $body = $this->streamBody('/check', ['domain' => 'example', 'tlds' => 'nl,com']);

        $this->assertStringContainsString('"status":"available"', $body);
        $this->assertStringContainsString('"error"', $body);
        $this->assertStringEndsWith("data: {\"done\":true}\n\n", $body);
    }

    public function test_stream_ends_with_done_when_every_tld_is_filtered_out(): void
    {
        $body = $this->streamBody('/check', ['domain' => 'example', 'tlds' => '-bad-,!!!']);

        $this->assertSame("data: {\"done\":true}\n\n", $body);
    }

    public function test_oversized_tld_labels_are_rejected_before_they_reach_the_cache(): void
    {
        $this->fakeAvailability();

        $body = $this->streamBody('/check', [
            'domain' => 'example',
            'tlds' => 'nl,'.str_repeat('a', 300),
        ]);

        $this->assertStringContainsString('"total":1', $body);
        $this->assertStringNotContainsString(str_repeat('a', 300), $body);
    }

    public function test_duplicate_tlds_do_not_inflate_the_total(): void
    {
        $this->fakeAvailability();

        $body = $this->streamBody('/check', ['domain' => 'example', 'tlds' => 'nl,nl,com,nl']);

        $this->assertStringContainsString('"total":2', $body);
        $this->assertStringNotContainsString('"total":4', $body);
    }

    private function fakeAvailability(): void
    {
        $this->mock(
            DomainAvailabilityService::class,
            fn ($mock) => $mock->shouldReceive('streamCheck')->andReturnUsing(
                function (string $domain, array $tlds, callable $onResult): void {
                    foreach ($tlds as $tld) {
                        $onResult($tld, 'available');
                    }
                },
            ),
        );
    }

    private function streamBody(string $uri, array $payload): string
    {
        $response = $this->post($uri, $payload);

        $response->assertOk();

        return $this->stripComments($response->streamedContent());
    }

    private function stripComments(string $body): string
    {
        // Drop the ": ping" heartbeat comment; it is transport, not data.
        return implode('', array_map(
            fn (string $chunk) => str_starts_with($chunk, ':') ? '' : $chunk,
            preg_split('/(?<=\n\n)/', $body, -1, PREG_SPLIT_NO_EMPTY) ?: [],
        ));
    }
}
