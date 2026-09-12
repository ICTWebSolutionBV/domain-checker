<?php

namespace Tests\Feature;

use App\Mail\UserInviteMail;
use App\Models\User;
use App\Models\UserInvite;
use App\Notifications\QueuedResetPassword;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Mail must not be sent inside the request. With a real SMTP host it is the
 * slowest thing these endpoints do, and a delivery failure used to surface as a
 * 500 *after* the invite row had already been written.
 */
class MailIsQueuedTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_an_invite_queues_the_mail_instead_of_sending_it(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($admin)->post(route('admin.invites.store'), [
            'email' => 'new@example.com',
            'role' => 'user',
            'expires_hours' => 72,
        ])->assertRedirect();

        Mail::assertQueued(UserInviteMail::class);
        Mail::assertNothingSent();

        $this->assertDatabaseHas('user_invites', ['email' => 'new@example.com']);
    }

    public function test_resending_an_invite_queues_the_mail(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['role' => 'super_admin']);

        $invite = UserInvite::create([
            'email' => 'pending@example.com',
            'token' => 'token-pending',
            'role' => 'user',
            'expires_at' => now()->addDay(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.invites.resend', $invite), ['expires_hours' => 24])
            ->assertRedirect();

        Mail::assertQueued(UserInviteMail::class);
        Mail::assertNothingSent();
    }

    public function test_the_invite_mailable_declares_itself_queueable(): void
    {
        $invite = UserInvite::create([
            'email' => 'pending@example.com',
            'token' => 'token-pending',
            'role' => 'user',
            'expires_at' => now()->addDay(),
        ]);

        $this->assertInstanceOf(ShouldQueue::class, new UserInviteMail($invite));
    }

    public function test_an_admin_triggered_password_reset_queues_its_notification(): void
    {
        Notification::fake();

        $admin = User::factory()->create(['role' => 'super_admin']);
        $user = User::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.users.password-reset', $user))
            ->assertRedirect();

        Notification::assertSentTo($user, QueuedResetPassword::class);
        $this->assertInstanceOf(ShouldQueue::class, new QueuedResetPassword('token'));
    }

    public function test_the_public_forgot_password_form_queues_its_notification(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, QueuedResetPassword::class);
    }
}
