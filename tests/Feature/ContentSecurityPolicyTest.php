<?php

namespace Tests\Feature;

use Tests\TestCase;

class ContentSecurityPolicyTest extends TestCase
{
    public function test_html_responses_carry_a_nonce_based_policy(): void
    {
        $policy = $this->get('/')->assertOk()->headers->get('Content-Security-Policy');

        $this->assertNotNull($policy);
        $this->assertMatchesRegularExpression("/script-src [^;]*'nonce-[A-Za-z0-9+\/=]{8,}'/", $policy);

        // The point of doing the nonce work at all: neither escape hatch is in
        // the policy. 'unsafe-inline' would let any injected script run, and
        // 'unsafe-eval' is unnecessary because the Vue templates are compiled
        // at build time.
        $this->assertStringNotContainsString("'unsafe-inline'", $policy);
        $this->assertStringNotContainsString("'unsafe-eval'", $policy);

        $this->assertStringContainsString("frame-ancestors 'none'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
    }

    /**
     * Vite emits absolute asset URLs built from ASSET_URL or APP_URL, so with a
     * CDN -- or a proxied hostname in front of the app -- the bundle is not on
     * the origin the visitor is browsing. Leaving that origin out of the policy
     * renders a blank page with every script refused, which is exactly what
     * happened the first time this policy was switched on.
     */
    public function test_the_asset_origin_is_allowed_even_when_it_is_not_the_request_host(): void
    {
        config(['app.url' => 'https://assets.example.com:8449']);

        $policy = $this->get('/')->assertOk()->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('script-src \'self\' \'nonce-', $policy);
        $this->assertStringContainsString('https://assets.example.com:8449', $policy);
    }

    public function test_the_shell_script_carries_the_nonce_from_the_header(): void
    {
        $response = $this->get('/')->assertOk();

        preg_match("/'nonce-([A-Za-z0-9+\/=]+)'/", (string) $response->headers->get('Content-Security-Policy'), $header);

        $this->assertNotEmpty($header[1] ?? null, 'the policy should carry a nonce');

        // The theme bootstrap is inline on purpose, so that the page cannot
        // flash the wrong theme. It only runs if it presents this nonce.
        $this->assertStringContainsString('<script nonce="'.$header[1].'"', $response->content());
    }

    public function test_a_json_response_gets_no_policy(): void
    {
        // The policy governs documents; sending it on an API response is noise.
        $response = $this->get('/tlds');

        $this->assertNull($response->headers->get('Content-Security-Policy'));
    }
}
