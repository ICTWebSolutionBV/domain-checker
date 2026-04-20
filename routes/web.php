<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasskeyLoginController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\DomainCheckController;
use App\Http\Controllers\Settings\SettingsController;
use App\Http\Controllers\TldController;
use Illuminate\Support\Facades\Route;

// Passkey auth options (must be before guest middleware)
Route::get('/passkeys/authentication-options', [PasskeyLoginController::class, 'options'])->name('passkeys.authentication_options');
Route::post('/passkeys/authenticate', [PasskeyLoginController::class, 'login'])->name('passkeys.login');

// Guest routes
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:5,1');
    Route::get('/two-factor', [TwoFactorController::class, 'challenge'])->name('two-factor.challenge');
    Route::post('/two-factor', [TwoFactorController::class, 'verify'])->middleware('throttle:10,1')->name('two-factor.verify');
    Route::post('/two-factor/cancel', [TwoFactorController::class, 'cancel'])->name('two-factor.cancel');
});

// Public domain checker
Route::get('/', [DomainCheckController::class, 'index'])->name('home');
Route::get('/check', [DomainCheckController::class, 'check'])->middleware('throttle:domain-check')->name('domain.check');
Route::get('/tlds', [TldController::class, 'index'])->name('tlds.index');

// Authenticated routes
Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    // Passkey management
    Route::get('/passkeys/register-options', [SettingsController::class, 'passkeyRegisterOptions'])->name('passkeys.register-options');
    Route::post('/passkeys', [SettingsController::class, 'storePasskey'])->name('passkeys.store');
    Route::delete('/passkeys/{passkey}', [SettingsController::class, 'destroyPasskey'])->name('passkeys.destroy');

    // Settings
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
    Route::put('/settings/profile', [SettingsController::class, 'updateProfile'])->name('settings.profile');
    Route::put('/settings/password', [SettingsController::class, 'updatePassword'])->name('settings.password');

    // API integrations
    Route::put('/settings/api', [SettingsController::class, 'updateApiSettings'])->name('settings.api');

    // 2FA management
    Route::post('/settings/two-factor/init', [SettingsController::class, 'initTwoFactor'])->name('settings.two-factor.init');
    Route::post('/settings/two-factor/confirm', [SettingsController::class, 'confirmTwoFactor'])->name('settings.two-factor.confirm');
    Route::post('/settings/two-factor/disable', [SettingsController::class, 'disableTwoFactor'])->name('settings.two-factor.disable');
});
