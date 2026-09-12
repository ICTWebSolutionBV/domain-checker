<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorController extends Controller
{
    public function challenge(): Response
    {
        if (! session('login.id')) {
            return Inertia::render('Auth/Login');
        }

        return Inertia::render('Auth/TwoFactorChallenge');
    }

    public function verify(Request $request): RedirectResponse
    {
        $request->validate([
            'code' => ['required', 'string'],
        ]);

        $userId = session('login.id');
        $remember = session('login.remember', false);

        if (! $userId) {
            return redirect()->route('login');
        }

        $user = User::find($userId);

        if (! $user || ! $user->two_factor_secret) {
            return redirect()->route('login');
        }

        $code = trim((string) $request->input('code'));
        $google2fa = new Google2FA;

        // A recovery code is the documented way back in when the authenticator
        // is gone. Settings hands the user eight of them, but neither challenge
        // page used to accept one, which made them decoration.
        if (! $google2fa->verifyKey(decrypt($user->two_factor_secret), $code)
            && ! $this->consumeRecoveryCode($user, $code)) {
            throw ValidationException::withMessages([
                'code' => ['The provided two factor code was invalid.'],
            ]);
        }

        Auth::login($user, $remember);
        $request->session()->forget(['login.id', 'login.remember']);
        $request->session()->put('two_factor_verified_for', $user->id);
        $request->session()->regenerate();

        return redirect()->intended('/');
    }

    public function cancel(Request $request): RedirectResponse
    {
        $request->session()->forget(['login.id', 'login.remember']);

        return redirect()->route('login');
    }

    /**
     * Spend one of the user's recovery codes, if the submitted code is one.
     */
    private function consumeRecoveryCode(User $user, string $code): bool
    {
        if ($code === '' || ! $user->two_factor_recovery_codes) {
            return false;
        }

        $codes = json_decode(decrypt($user->two_factor_recovery_codes), true);

        if (! is_array($codes)) {
            return false;
        }

        $submitted = strtoupper($code);

        foreach ($codes as $index => $candidate) {
            if (is_string($candidate) && hash_equals(strtoupper($candidate), $submitted)) {
                unset($codes[$index]);

                $user->forceFill([
                    'two_factor_recovery_codes' => encrypt(json_encode(array_values($codes))),
                ])->save();

                return true;
            }
        }

        return false;
    }
}
