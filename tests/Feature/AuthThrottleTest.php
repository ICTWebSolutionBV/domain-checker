<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('login');
    }

    /**
     * The per-IP limit is keyed on a header we cannot fully trust, so it can be
     * rotated. The per-account limit is the one that has to hold.
     */
    public function test_rotating_the_forwarded_ip_does_not_buy_unlimited_guesses(): void
    {
        $user = User::factory()->create(['password' => 'password']);

        $lastStatus = null;

        for ($attempt = 1; $attempt <= 25; $attempt++) {
            $response = $this->post('/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ], ['X-Forwarded-For' => '203.0.113.'.$attempt]);

            $lastStatus = $response->getStatusCode();
        }

        $this->assertSame(429, $lastStatus, 'the per-account limit should have stopped this');
        $this->assertGuest();
    }

    public function test_the_login_limiter_is_actually_registered(): void
    {
        // It used to live in a service provider that bootstrap/providers.php
        // never registered, so the named limiter did not exist at all.
        $this->assertNotNull(RateLimiter::limiter('login'));
        $this->assertNotNull(RateLimiter::limiter('two-factor'));
    }

    public function test_a_correct_password_still_gets_through(): void
    {
        $user = User::factory()->create(['password' => 'password']);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect();

        $this->assertAuthenticatedAs($user);
    }
}
