<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use PragmaRX\Google2FA\Google2FA;
use Spatie\LaravelPasskeys\Actions\GeneratePasskeyRegisterOptionsAction;
use Spatie\LaravelPasskeys\Actions\StorePasskeyAction;
use Spatie\LaravelPasskeys\Models\Passkey;

class SettingsController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $twoFactorEnabled = (bool) $user->two_factor_secret;
        $qrCodeSvg = null;
        $recoveryCodes = null;

        if (session('show_two_factor_qr')) {
            $google2fa = new Google2FA();
            $secret = session('two_factor_secret_setup');
            $qrCodeSvg = $google2fa->getQRCodeUrl(
                config('app.name'),
                $user->email,
                $secret,
            );
        }

        if (session('show_recovery_codes')) {
            $recoveryCodes = $user->two_factor_recovery_codes
                ? json_decode(decrypt($user->two_factor_recovery_codes), true)
                : [];
        }

        return Inertia::render('Settings/Index', [
            'twoFactorEnabled' => $twoFactorEnabled,
            'qrCodeUrl' => $qrCodeSvg,
            'setupSecret' => session('two_factor_secret_setup'),
            'recoveryCodes' => $recoveryCodes,
            'passkeys' => $user->passkeys->map(fn (Passkey $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'last_used_at' => $p->last_used_at?->diffForHumans(),
                'created_at' => $p->created_at->format('d M Y'),
            ]),
            'rtrConfigured' => (bool) Setting::get('realtime_register_api_key'),
            'rtrBaseUrl' => Setting::get('realtime_register_base_url', 'https://api.yoursrs.com'),
        ]);
    }

    public function initTwoFactor(Request $request): RedirectResponse
    {
        $google2fa = new Google2FA();
        $secret = $google2fa->generateSecretKey();

        $request->session()->put('two_factor_secret_setup', $secret);
        $request->session()->put('show_two_factor_qr', true);

        return redirect()->route('settings');
    }

    public function confirmTwoFactor(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string', 'digits:6']]);

        $secret = session('two_factor_secret_setup');

        if (! $secret) {
            return back()->withErrors(['code' => 'Setup session expired. Please start again.']);
        }

        $google2fa = new Google2FA();

        if (! $google2fa->verifyKey($secret, $request->input('code'))) {
            return back()->withErrors(['code' => 'Invalid code. Please try again.']);
        }

        /** @var User $user */
        $user = $request->user();
        $recoveryCodes = $this->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_secret' => encrypt($secret),
            'two_factor_recovery_codes' => encrypt(json_encode($recoveryCodes)),
        ])->save();

        $request->session()->forget(['two_factor_secret_setup', 'show_two_factor_qr']);
        $request->session()->put('show_recovery_codes', true);

        return redirect()->route('settings')->with('status', 'Two-factor authentication enabled.');
    }

    public function disableTwoFactor(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'string', 'current_password']]);

        /** @var User $user */
        $user = $request->user();
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
        ])->save();

        return redirect()->route('settings')->with('status', 'Two-factor authentication disabled.');
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', "unique:users,email,{$request->user()->id}"],
        ]);

        $request->user()->update($request->only('name', 'email'));

        return back()->with('status', 'Profile updated.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $request->user()->update(['password' => Hash::make($request->input('password'))]);

        return back()->with('status', 'Password updated.');
    }

    public function passkeyRegisterOptions(Request $request, GeneratePasskeyRegisterOptionsAction $generateOptions): mixed
    {
        $options = $generateOptions->execute($request->user());
        session()->put('passkey-register-options', $options);

        return $options;
    }

    public function storePasskey(Request $request, StorePasskeyAction $storePasskey): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'passkey_response' => ['required', 'json'],
        ]);

        try {
            $storePasskey->execute(
                $request->user(),
                $request->input('passkey_response'),
                session()->get('passkey-register-options'),
                $request->getHost(),
                ['name' => $request->input('name')],
            );
        } catch (\Throwable $e) {
            return back()->withErrors(['passkey' => 'Failed to register passkey. Please try again.']);
        }

        return back()->with('status', 'Passkey added successfully.');
    }

    public function destroyPasskey(Request $request, Passkey $passkey): RedirectResponse
    {
        abort_unless($passkey->authenticatable_id === $request->user()->id, 403);
        $passkey->delete();

        return back()->with('status', 'Passkey removed.');
    }

    public function updateApiSettings(Request $request): RedirectResponse
    {
        $request->validate([
            'api_key'  => ['nullable', 'string', 'max:500'],
            'base_url' => ['nullable', 'url', 'max:255'],
            'clear'    => ['nullable', 'boolean'],
        ]);

        if ($request->boolean('clear')) {
            Setting::set('realtime_register_api_key', null);
        } elseif ($request->filled('api_key')) {
            Setting::set('realtime_register_api_key', $request->input('api_key'));
        }

        if ($request->filled('base_url')) {
            Setting::set('realtime_register_base_url', $request->input('base_url'));
        }

        return back()->with('status', 'API settings updated.');
    }

    private function generateRecoveryCodes(): array
    {
        return array_map(
            fn () => implode('-', [
                strtoupper(bin2hex(random_bytes(5))),
                strtoupper(bin2hex(random_bytes(5))),
            ]),
            range(1, 8),
        );
    }
}
