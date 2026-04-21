<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserInvite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class InviteController extends Controller
{
    public function show(string $token)
    {
        $invite = UserInvite::where('token', $token)->first();
        $user   = Auth::user();

        // If a valid invite exists for a different logged-in user, sign them out.
        if ($invite && $invite->isValid() && $user && $user->email !== $invite->email) {
            Auth::logout();
            request()->session()->invalidate();
            request()->session()->regenerateToken();

            return redirect()->route('invite.show', ['token' => $token]);
        }

        // Already authenticated → they've been created; redirect to home/settings.
        if ($user) {
            return redirect()->route('home');
        }

        // Unknown token
        if (! $invite) {
            return Inertia::render('Auth/InviteInvalid', ['reason' => 'not_found']);
        }

        // Expired or used
        if (! $invite->isValid()) {
            $existingUser = User::where('email', $invite->email)->first();
            if ($existingUser) {
                return redirect()->route('login')
                    ->with('status', 'An account already exists for this email. Please sign in.')
                    ->withInput(['email' => $invite->email]);
            }

            return Inertia::render('Auth/InviteInvalid', [
                'reason' => $invite->isUsed() ? 'used' : 'expired',
            ]);
        }

        // Account already exists (invite resent after signup)
        if (User::where('email', $invite->email)->exists()) {
            UserInvite::where('email', $invite->email)->delete();

            return redirect()->route('login')
                ->with('status', 'An account already exists for this email. Please sign in.')
                ->withInput(['email' => $invite->email]);
        }

        return Inertia::render('Auth/AcceptInvite', [
            'token'      => $invite->token,
            'email'      => $invite->email,
            'first_name' => $invite->first_name ?? '',
            'last_name'  => $invite->last_name ?? '',
            'expires_at' => $invite->expires_at->toISOString(),
        ]);
    }

    public function accept(Request $request, string $token)
    {
        $invite = UserInvite::where('token', $token)->first();

        if (! $invite || ! $invite->isValid()) {
            return redirect()->route('login')->withErrors(['email' => 'This invite is no longer valid.']);
        }

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name'  => ['nullable', 'string', 'max:100'],
            'password'   => ['required', 'confirmed', Password::defaults()],
        ]);

        if (User::where('email', $invite->email)->exists()) {
            UserInvite::where('email', $invite->email)->delete();

            return redirect()->route('login')->withErrors(['email' => 'An account with this email already exists.']);
        }

        $user = User::create([
            'first_name' => $validated['first_name'],
            'last_name'  => $validated['last_name'] ?? null,
            'name'       => trim($validated['first_name'].' '.($validated['last_name'] ?? '')),
            'email'      => $invite->email,
            'password'   => Hash::make($validated['password']),
            'role'       => $invite->role,
        ]);

        // Remove all pending invites for this email.
        UserInvite::where('email', $invite->email)->delete();

        Auth::login($user);

        return redirect()->route('home');
    }
}
