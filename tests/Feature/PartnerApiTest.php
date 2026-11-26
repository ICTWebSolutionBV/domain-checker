<?php

namespace Tests\Feature;

use App\Services\DomainAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PartnerApiTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-token-0123456789-0123456789-0123456789';

    private const IANA = ['com', 'nl', 'eu', 'de', 'xn--p1ai'];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'domain-checker.partner_api.clients' => 'other:'.hash('sha256', 'unrelated').',website:'.hash('sha256', self::TOKEN),
            'domain-checker.partner_api.per_client_per_minute' => 5,
            'domain-checker.partner_api.per_visitor_per_minute' => 2,
        ]);

        // Stands in for the IANA list, which is otherwise fetched over the network.
        Cache::put('iana_tld_list', self::IANA, 60);
    }

    /**
     * The contract: byte for byte the stream the public checker sends for the
     * same name and TLDs, ping included.
     */
    public function test_the_stream_is_the_same_as_the_public_check(): void
    {
        $this->fakeAvailability();

        $api = $this->check(['domain' => 'example', 'tlds' => ['nl', 'COM', 'nl', 'eu']])->assertOk();
        $public = $this->post('/check', ['domain' => 'example', 'tlds' => 'nl,com,eu'])->assertOk();

        $this->assertStringStartsWith('text/event-stream', (string) $api->headers->get('Content-Type'));
        $this->assertSame($public->streamedContent(), $api->streamedContent());
        $this->assertSame(
            ": ping\n\n"
            ."data: {\"tld\":\"nl\",\"status\":\"available\",\"checked\":1,\"total\":3}\n\n"
            ."data: {\"tld\":\"com\",\"status\":\"available\",\"checked\":2,\"total\":3}\n\n"
            ."data: {\"tld\":\"eu\",\"status\":\"available\",\"checked\":3,\"total\":3}\n\n"
            ."data: {\"done\":true}\n\n",
            $api->streamedContent(),
        );
    }

    public function test_a_unicode_name_reaches_the_lookup_as_punycode(): void
    {
        $this->mock(DomainAvailabilityService::class, fn ($mock) => $mock
            ->shouldReceive('streamCheck')->once()->withArgs(fn (string $domain) => $domain === 'xn--mnchen-3ya'));

        $this->check(['domain' => 'münchen', 'tlds' => ['de']])->assertOk()->streamedContent();
    }

    public function test_a_missing_or_unknown_token_is_a_json_401_without_logging_the_token(): void
    {
        $log = Log::spy();

        $this->check(['domain' => 'example', 'tlds' => ['nl']], token: null)
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer')
            ->assertExactJson(['message' => 'Unauthenticated.']);

        $this->check(['domain' => 'example', 'tlds' => ['nl']], token: 'wrong-'.self::TOKEN)->assertUnauthorized();
        $this->getJson('/api/v1/tlds', ['Authorization' => 'Bearer wrong'])->assertUnauthorized();

        $log->shouldHaveReceived('warning')->times(3)->withArgs(
            fn (string $message, array $context) => ! str_contains(json_encode($context), self::TOKEN)
                && ! str_contains(json_encode($context), '127.0.0.1'),
        );
    }

    public function test_invalid_input_is_a_json_422_even_when_a_stream_was_asked_for(): void
    {
        // A rejected body still counts against the limit; six of them pass 5.
        config(['domain-checker.partner_api.per_client_per_minute' => 100]);

        $this->check(['domain' => 'example.nl', 'tlds' => ['nl']])->assertUnprocessable()->assertJsonValidationErrors('domain');
        $this->check(['domain' => ['example'], 'tlds' => ['nl']])->assertUnprocessable()->assertJsonValidationErrors('domain');
        $this->check(['domain' => 'example', 'tlds' => 'nl,com'])->assertUnprocessable()->assertJsonValidationErrors('tlds');
        $this->check(['domain' => 'example', 'tlds' => ['nl', '-bad']])->assertUnprocessable()->assertJsonValidationErrors('tlds.1');
        $this->check(['domain' => 'example', 'tlds' => [str_repeat('a', 64)]])->assertUnprocessable()->assertJsonValidationErrors('tlds.0');
        $this->check(['domain' => 'example', 'tlds' => array_fill(0, 101, 'nl')])->assertUnprocessable()->assertJsonValidationErrors('tlds');
    }

    public function test_each_visitor_has_its_own_limit_within_the_client_limit(): void
    {
        $this->fakeAvailability();
        $log = Log::spy();

        $this->check(['domain' => 'a', 'tlds' => ['nl']], visitor: '203.0.113.7')->assertOk();
        $this->check(['domain' => 'a', 'tlds' => ['nl']], visitor: '203.0.113.7')->assertOk();

        $this->check(['domain' => 'a', 'tlds' => ['nl']], visitor: '203.0.113.7')
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertExactJson(['message' => 'Too Many Attempts.']);

        // Another visitor of the same site is not affected...
        $this->check(['domain' => 'a', 'tlds' => ['nl']], visitor: '2001:db8::1')->assertOk();
        // ...nor is a request without a usable visitor IP, until the client limit (5) is spent.
        $this->check(['domain' => 'a', 'tlds' => ['nl']], visitor: 'not-an-ip')->assertOk();
        $this->check(['domain' => 'a', 'tlds' => ['nl']])->assertOk();
        $this->check(['domain' => 'a', 'tlds' => ['nl']])
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertExactJson(['message' => 'Too Many Attempts.']);

        foreach ([
            ['client' => 'website', 'endpoint' => 'check', 'limit' => 'visitor'],
            ['client' => 'website', 'endpoint' => 'check', 'limit' => 'client'],
            // A dropped visitor limit is reported, without the header's value.
            ['client' => 'website', 'reason' => 'invalid'],
            ['client' => 'website', 'reason' => 'missing'],
        ] as $expected) {
            $log->shouldHaveReceived('notice')->withArgs(fn (string $message, array $context) => $context === $expected);
        }
    }

    /**
     * One IPv6 subscriber holds a whole /64; rotating through it must not
     * buy a fresh budget, and an IPv4-mapped spelling is the same visitor.
     */
    public function test_visitors_are_limited_per_ipv6_64_and_per_ipv4_whatever_the_spelling(): void
    {
        $this->fakeAvailability();
        config(['domain-checker.partner_api.per_client_per_minute' => 100]);

        $this->check(['domain' => 'a', 'tlds' => ['nl']], visitor: '2001:db8::1')->assertOk();
        $this->check(['domain' => 'a', 'tlds' => ['nl']], visitor: '2001:db8::2')->assertOk();
        $this->check(['domain' => 'a', 'tlds' => ['nl']], visitor: '2001:db8::ffff:3')->assertTooManyRequests();
        $this->check(['domain' => 'a', 'tlds' => ['nl']], visitor: '2001:db8:0:1::1')->assertOk();

        $this->check(['domain' => 'a', 'tlds' => ['nl']], visitor: '203.0.113.9')->assertOk();
        $this->check(['domain' => 'a', 'tlds' => ['nl']], visitor: '::ffff:203.0.113.9')->assertOk();
        $this->check(['domain' => 'a', 'tlds' => ['nl']], visitor: '203.0.113.9')->assertTooManyRequests();
    }

    public function test_an_empty_client_list_rejects_every_token(): void
    {
        config(['domain-checker.partner_api.clients' => '']);

        foreach (['', self::TOKEN, 'anything'] as $token) {
            $this->getJson('/api/v1/tlds', ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
        }
    }

    /**
     * Rotation is two entries under one name: both tokens work during the
     * switch-over, and they share the client's budget rather than doubling it.
     */
    public function test_two_tokens_under_one_name_both_work_and_share_a_budget(): void
    {
        $this->fakeAvailability();
        config([
            'domain-checker.partner_api.clients' => 'website:'.hash('sha256', self::TOKEN).',website:'.hash('sha256', 'new-'.self::TOKEN),
            'domain-checker.partner_api.per_client_per_minute' => 2,
        ]);

        $this->check(['domain' => 'a', 'tlds' => ['nl']])->assertOk();
        $this->check(['domain' => 'a', 'tlds' => ['nl']], token: 'new-'.self::TOKEN)->assertOk();
        $this->check(['domain' => 'a', 'tlds' => ['nl']], token: 'new-'.self::TOKEN)->assertTooManyRequests();
    }

    /**
     * Unauthenticated requests are not covered by the client limits, so each
     * one used to write a warning; past a coarse per-IP cap they write nothing.
     */
    public function test_failed_authentication_is_capped_per_ip_and_stops_logging(): void
    {
        $log = Log::spy();
        config(['domain-checker.partner_api.failed_auth_per_minute' => 2]);

        $this->getJson('/api/v1/tlds', ['Authorization' => 'Bearer wrong'])->assertUnauthorized();
        $this->getJson('/api/v1/tlds', ['Authorization' => 'Bearer wrong'])->assertUnauthorized();
        $this->getJson('/api/v1/tlds', ['Authorization' => 'Bearer wrong'])
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertExactJson(['message' => 'Too Many Attempts.']);

        // A valid token from the same address is not held back.
        $this->getJson('/api/v1/tlds', ['Authorization' => 'Bearer '.self::TOKEN])->assertOk();

        $log->shouldHaveReceived('warning')->twice();
    }

    /**
     * shouldRenderJsonWhen() only widens JSON errors to api/*: the public
     * checker still redirects a browser form back with errors, and still
     * answers a client that asks for JSON with a JSON 422.
     */
    public function test_the_public_check_keeps_its_validation_responses(): void
    {
        $this->post('/check', ['domain' => 'not valid', 'tlds' => 'nl'])
            ->assertRedirect()
            ->assertSessionHasErrors('domain');

        $this->postJson('/check', ['domain' => 'not valid', 'tlds' => 'nl'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('domain');
    }

    /**
     * X-Client-IP is only believed after the token is: a caller without one
     * must neither be throttled ahead of the 401 nor spend a visitor's budget.
     */
    public function test_rejected_requests_do_not_touch_the_rate_limits(): void
    {
        $this->fakeAvailability();

        for ($i = 0; $i < 5; $i++) {
            $this->check(['domain' => 'a', 'tlds' => ['nl']], token: 'wrong', visitor: '203.0.113.7')->assertUnauthorized();
        }

        $this->check(['domain' => 'a', 'tlds' => ['nl']], visitor: '203.0.113.7')->assertOk();
    }

    public function test_the_api_is_stateless_and_sends_no_cors_headers(): void
    {
        $this->fakeAvailability();

        $headers = ['Origin' => 'https://elsewhere.example', 'Authorization' => 'Bearer '.self::TOKEN];

        foreach ([$this->getJson('/api/v1/tlds', $headers), $this->check(['domain' => 'a', 'tlds' => ['nl']])] as $response) {
            $response->assertOk()->assertHeaderMissing('Set-Cookie')->assertHeaderMissing('Access-Control-Allow-Origin');
        }
    }

    public function test_tlds_returns_the_popular_and_the_full_iana_list(): void
    {
        $this->getJson('/api/v1/tlds', ['Authorization' => 'Bearer '.self::TOKEN])
            ->assertOk()
            ->assertExactJson(['popular' => config('domain-checker.popular_tlds'), 'all' => self::IANA]);
    }

    /**
     * scope=all is the public "All extensions" mode: the same list, sent the
     * way that mode sends it, gives the same stream.
     */
    public function test_scope_all_checks_the_full_iana_list(): void
    {
        $this->fakeAvailability();

        $api = $this->check(['domain' => 'example', 'scope' => 'all'])->assertOk()->streamedContent();
        $public = $this->post('/check', ['domain' => 'example', 'tlds' => implode(',', self::IANA)])->assertOk()->streamedContent();

        $this->assertSame($public, $api);
        $this->assertSame(count(self::IANA), substr_count($api, '"status":"available"'));
        $this->assertStringContainsString('{"tld":"xn--p1ai","status":"available","checked":5,"total":5}', $api);
        $this->assertStringEndsWith("data: {\"done\":true}\n\n", $api);
    }

    public function test_tlds_and_scope_are_mutually_exclusive(): void
    {
        $this->check(['domain' => 'example', 'tlds' => ['nl'], 'scope' => 'all'])
            ->assertUnprocessable()->assertJsonValidationErrors('tlds');
        $this->check(['domain' => 'example'])
            ->assertUnprocessable()->assertJsonValidationErrors(['tlds', 'scope']);
        $this->check(['domain' => 'example', 'scope' => 'popular'])
            ->assertUnprocessable()->assertJsonValidationErrors('scope');
    }

    public function test_scope_all_has_its_own_stricter_limits(): void
    {
        $this->fakeAvailability();
        $log = Log::spy();
        config([
            'domain-checker.partner_api.all_per_visitor_per_10_minutes' => 1,
            'domain-checker.partner_api.all_per_client_per_hour' => 2,
        ]);

        $this->check(['domain' => 'a', 'scope' => 'all'], visitor: '203.0.113.7')->assertOk();

        $limited = $this->check(['domain' => 'a', 'scope' => 'all'], visitor: '203.0.113.7')
            ->assertTooManyRequests()
            ->assertExactJson(['message' => 'Too Many Attempts.']);
        $this->assertGreaterThan(60, (int) $limited->headers->get('Retry-After'), 'a 10-minute window');

        // The same visitor's ordinary checks have their own budget...
        $this->check(['domain' => 'a', 'tlds' => ['nl']], visitor: '203.0.113.7')->assertOk();
        // ...and the token's hourly budget of full checks (2) is shared by all visitors.
        $this->check(['domain' => 'a', 'scope' => 'all'], visitor: '203.0.113.8')->assertOk();
        $this->check(['domain' => 'a', 'scope' => 'all'], visitor: '203.0.113.9')->assertTooManyRequests();

        $log->shouldHaveReceived('notice')->withArgs(
            fn (string $message, array $context) => $context === ['client' => 'website', 'endpoint' => 'all', 'limit' => 'client'],
        );
    }

    /**
     * The public bulk lines, keyed on the line as sent, plus an 'invalid' line
     * for anything that is no domain or has an extension no registry knows.
     */
    public function test_bulk_streams_the_public_lines_and_answers_unusable_lines_as_invalid(): void
    {
        $this->fakeAvailability();

        $body = $this->bulk(['domains' => ['blog.example.com', 'not a domain', 'example.nl', 'example.notatld', 'https://www.EXAMPLE.nl']])
            ->assertOk()
            ->streamedContent();

        $this->assertSame(
            ": ping\n\n"
            ."data: {\"domain\":\"not a domain\",\"status\":\"invalid\",\"checked\":1,\"total\":5}\n\n"
            ."data: {\"domain\":\"example.notatld\",\"status\":\"invalid\",\"checked\":2,\"total\":5}\n\n"
            ."data: {\"domain\":\"blog.example.com\",\"status\":\"available\",\"checked\":3,\"total\":5,\"checked_domain\":\"example.com\"}\n\n"
            ."data: {\"domain\":\"example.nl\",\"status\":\"available\",\"checked\":4,\"total\":5}\n\n"
            ."data: {\"domain\":\"https:\\/\\/www.EXAMPLE.nl\",\"status\":\"available\",\"checked\":5,\"total\":5}\n\n"
            ."data: {\"done\":true}\n\n",
            $body,
        );

        $public = $this->post('/bulk-check', ['domains' => "blog.example.com\nexample.nl"])->assertOk()->streamedContent();
        $this->assertSame($public, $this->bulk(['domains' => ['blog.example.com', 'example.nl']])->assertOk()->streamedContent());
    }

    public function test_bulk_input_is_validated(): void
    {
        config([
            'domain-checker.partner_api.bulk_max_domains' => 3,
            'domain-checker.partner_api.bulk_per_client_per_minute' => 100,
        ]);

        $this->bulk(['domains' => 'example.nl'])->assertUnprocessable()->assertJsonValidationErrors('domains');
        $this->bulk([])->assertUnprocessable()->assertJsonValidationErrors('domains');
        $this->bulk(['domains' => ['a.nl', 'b.nl', 'c.nl', 'd.nl']])->assertUnprocessable()->assertJsonValidationErrors('domains');
        $this->bulk(['domains' => [['a.nl']]])->assertUnprocessable()->assertJsonValidationErrors('domains.0');
    }

    public function test_bulk_has_its_own_limits(): void
    {
        $this->fakeAvailability();
        config([
            'domain-checker.partner_api.bulk_per_visitor_per_minute' => 1,
            'domain-checker.partner_api.bulk_per_client_per_minute' => 2,
        ]);

        $this->bulk(['domains' => ['example.nl']], visitor: '203.0.113.7')->assertOk();
        $this->bulk(['domains' => ['example.nl']], visitor: '203.0.113.7')->assertTooManyRequests()->assertHeader('Retry-After');
        $this->check(['domain' => 'example', 'tlds' => ['nl']], visitor: '203.0.113.7')->assertOk();
        $this->bulk(['domains' => ['example.nl']], visitor: '203.0.113.8')->assertOk();
        $this->bulk(['domains' => ['example.nl']], visitor: '203.0.113.9')->assertTooManyRequests();
    }

    public function test_the_token_command_prints_a_working_token_and_only_its_hash_for_the_env(): void
    {
        Artisan::call('partner-api:token', ['name' => 'staging']);
        $output = Artisan::output();

        $this->assertMatchesRegularExpression('/^  ([A-Za-z0-9]{48})$/m', $output);
        preg_match('/^  ([A-Za-z0-9]{48})$/m', $output, $token);
        preg_match('/^  PARTNER_API_CLIENTS=(\S+)$/m', $output, $line);

        // Existing clients are kept, the new one is appended as name:hash.
        $this->assertStringStartsWith(config('domain-checker.partner_api.clients').',staging:', $line[1]);
        $this->assertStringEndsWith('staging:'.hash('sha256', $token[1]), $line[1]);

        config(['domain-checker.partner_api.clients' => $line[1]]);
        $this->getJson('/api/v1/tlds', ['Authorization' => 'Bearer '.$token[1]])->assertOk();

        $this->assertSame(1, Artisan::call('partner-api:token', ['name' => 'bad:name']));
    }

    private function bulk(array $payload, ?string $visitor = null): TestResponse
    {
        return $this->check($payload, visitor: $visitor, uri: '/api/v1/bulk-check');
    }

    private function check(array $payload, ?string $token = self::TOKEN, ?string $visitor = null, string $uri = '/api/v1/check'): TestResponse
    {
        return $this->json('POST', $uri, $payload, array_filter([
            'Accept' => 'text/event-stream',
            'Authorization' => $token === null ? null : 'Bearer '.$token,
            'X-Client-IP' => $visitor,
        ]));
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
}
