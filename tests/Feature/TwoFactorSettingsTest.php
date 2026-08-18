<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirming_two_factor_marks_it_confirmed(): void
    {
        $user = User::factory()->create();
        $google2fa = new Google2FA;
        $secret = $google2fa->generateSecretKey();

        $this->actingAs($user)
            ->withSession(['two_factor_secret_setup' => $secret])
            ->post(route('settings.two-factor.confirm'), [
                'code' => $google2fa->getCurrentOtp($secret),
            ])
            ->assertRedirect(route('settings'));

        $user->refresh();

        $this->assertNotNull($user->two_factor_secret);
        $this->assertNotNull($user->two_factor_confirmed_at);
        $this->assertTrue($user->hasTotpEnabled());
    }
}
