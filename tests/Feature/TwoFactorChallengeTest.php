<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorChallengeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_valid_totp_code_completes_the_challenge(): void
    {
        $google2fa = new Google2FA;
        $secret = $google2fa->generateSecretKey();
        $user = $this->userWithTwoFactor($secret);

        $this->withSession(['login.id' => $user->id])
            ->post(route('two-factor.verify'), ['code' => $google2fa->getCurrentOtp($secret)])
            ->assertRedirect();

        $this->assertAuthenticatedAs($user);
        $this->assertSame($user->id, session('two_factor_verified_for'));
    }

    /**
     * Settings hands out eight recovery codes; before this they were accepted
     * nowhere, so losing the authenticator meant losing the account.
     */
    public function test_a_recovery_code_completes_the_challenge_and_is_spent(): void
    {
        $user = $this->userWithTwoFactor((new Google2FA)->generateSecretKey(), ['code-one', 'code-two']);

        $this->withSession(['login.id' => $user->id])
            ->post(route('two-factor.verify'), ['code' => 'code-one'])
            ->assertRedirect();

        $this->assertAuthenticatedAs($user);

        $remaining = json_decode(decrypt($user->fresh()->two_factor_recovery_codes), true);
        $this->assertSame(['code-two'], $remaining);
    }

    public function test_a_recovery_code_is_accepted_in_the_case_the_user_typed(): void
    {
        $user = $this->userWithTwoFactor((new Google2FA)->generateSecretKey(), ['AAAAA11111-BBBBB22222']);

        // The codes are shown uppercase; nobody should be locked out for
        // pasting them back in lowercase.
        $this->withSession(['login.id' => $user->id])
            ->post(route('two-factor.verify'), ['code' => 'aaaaa11111-bbbbb22222'])
            ->assertRedirect();

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_spent_recovery_code_is_refused(): void
    {
        $user = $this->userWithTwoFactor((new Google2FA)->generateSecretKey(), ['code-two']);

        $this->withSession(['login.id' => $user->id])
            ->post(route('two-factor.verify'), ['code' => 'code-one'])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_the_grant_of_another_user_does_not_skip_the_challenge(): void
    {
        $google2fa = new Google2FA;
        $secret = $google2fa->generateSecretKey();
        $user = $this->userWithTwoFactor($secret);
        $someoneElse = User::factory()->create();

        // A session that already cleared 2FA for a different account must not
        // carry that clearance over to this one.
        $this->withSession(['two_factor_verified_for' => $someoneElse->id])
            ->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('two-factor.challenge'));

        $this->assertGuest();
    }

    private function userWithTwoFactor(string $secret, ?array $recoveryCodes = null): User
    {
        $user = User::factory()->create(['password' => 'password']);

        $user->forceFill([
            'two_factor_secret' => encrypt($secret),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $recoveryCodes ? encrypt(json_encode($recoveryCodes)) : null,
        ])->save();

        return $user->fresh();
    }
}
