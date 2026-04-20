<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
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

        $email = session('login.id');
        $remember = session('login.remember', false);

        if (! $email) {
            return redirect()->route('login');
        }

        $user = \App\Models\User::where('email', $email)->first();

        if (! $user || ! $user->two_factor_secret) {
            return redirect()->route('login');
        }

        $google2fa = new Google2FA();
        $secret = decrypt($user->two_factor_secret);

        if (! $google2fa->verifyKey($secret, $request->input('code'))) {
            throw ValidationException::withMessages([
                'code' => ['The provided two factor code was invalid.'],
            ]);
        }

        Auth::login($user, $remember);
        $request->session()->forget(['login.id', 'login.remember']);
        $request->session()->put('two_factor_verified', true);
        $request->session()->regenerate();

        return redirect()->intended('/');
    }

    public function cancel(Request $request): RedirectResponse
    {
        $request->session()->forget(['login.id', 'login.remember']);

        return redirect()->route('login');
    }
}
