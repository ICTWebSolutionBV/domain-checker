<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\QueuedResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_baseline_security_headers_are_sent(): void
    {
        $response = $this->get('/');

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        // Without this, a full URL -- including a reset token -- travels in the
        // Referer to every third-party host the page fetches from.
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    /**
     * "We can't find a user with that email address" told anyone who asked
     * which addresses have accounts here.
     */
    public function test_the_reset_form_answers_the_same_way_for_unknown_addresses(): void
    {
        User::factory()->create(['email' => 'known@example.com']);

        $this->post('/forgot-password', ['email' => 'known@example.com'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', __('passwords.sent'));

        $this->post('/forgot-password', ['email' => 'nobody@example.com'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', __('passwords.sent'));
    }

    public function test_a_reset_link_ignores_a_forged_forwarded_host(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email], [
            'X-Forwarded-Host' => 'evil.example.com',
        ]);

        // The notification is queued now, so it is the QueuedResetPassword
        // subclass that gets sent; the assertion below is unchanged.
        Notification::assertSentTo($user, QueuedResetPassword::class, function (QueuedResetPassword $notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;

            // The whole point: a forged host used to produce a mail carrying a
            // valid token to a server the attacker controls.
            $this->assertStringNotContainsString('evil.example.com', $url);
            $this->assertStringStartsWith(config('app.url'), $url);

            return true;
        });
    }
}
