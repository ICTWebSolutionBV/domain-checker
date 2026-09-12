<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\UserInviteMail;
use App\Models\User;
use App\Models\UserInvite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class UserController extends Controller
{
    /** Rows per page on the index. Neither list was paginated at all. */
    private const PER_PAGE = 25;

    /**
     * Roles assignable by the current actor. Only super admins can grant
     * the super_admin role; regular admins are limited to user/admin.
     */
    private function assignableRoles(Request $request): array
    {
        return $request->user()?->isSuperAdmin()
            ? ['user', 'admin', 'super_admin']
            : ['user', 'admin'];
    }

    public function index(Request $request)
    {
        // Spent invites are hidden rather than deleted. This page used to run a
        // DELETE on a plain GET, which re-fired on every refresh, Inertia
        // partial reload and browser prefetch; `php artisan invites:prune`
        // (schedule it) does the actual removal now. The email filter is a
        // subquery, so the whole user table no longer has to be loaded into an
        // unbounded IN (...) list to render one page.
        $invites = UserInvite::query()
            ->with('inviter:id,name')
            ->whereNull('used_at')
            ->whereNotIn('email', User::query()->select('email'))
            ->latest()
            ->paginate(self::PER_PAGE, ['*'], 'invites_page')
            ->withQueryString();

        // withCount instead of hasPasskeysEnabled() per row: that accessor is
        // ->passkeys()->exists(), which made the page cost one query per user
        // (21 users measured at 22 queries).
        $users = User::query()
            ->withCount('passkeys')
            ->latest()
            ->paginate(self::PER_PAGE, ['*'], 'users_page')
            ->withQueryString();

        return Inertia::render('Admin/Users/Index', [
            'users' => $users->through(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'created_at' => $user->created_at->toISOString(),
                'two_factor' => [
                    'totp_enabled' => $user->hasTotpEnabled(),
                    'passkeys_enabled' => $user->passkeys_count > 0,
                ],
            ]),
            'invites' => $invites->through(fn (UserInvite $invite) => [
                'id' => $invite->id,
                'email' => $invite->email,
                'role' => $invite->role,
                'inviter' => $invite->inviter?->name,
                'expires_at' => $invite->expires_at->toISOString(),
                'used_at' => $invite->used_at?->toISOString(),
                'is_valid' => $invite->isValid(),
            ]),
            'assignableRoles' => $this->assignableRoles($request),
        ]);
    }

    public function create(Request $request)
    {
        return Inertia::render('Admin/Users/Create', [
            'assignableRoles' => $this->assignableRoles($request),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['required', 'email', 'unique:users'],
            'password' => ['required', Password::defaults(), 'confirmed'],
            'role' => ['required', 'in:'.implode(',', $this->assignableRoles($request))],
        ]);

        User::create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'] ?? null,
            'name' => trim($data['first_name'].' '.($data['last_name'] ?? '')),
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => $data['role'],
        ]);

        return redirect()->route('admin.users.index')->with('success', 'User created.');
    }

    public function edit(User $user, Request $request)
    {
        $this->authorizeSuperAdminTarget($request, $user);

        return Inertia::render('Admin/Users/Edit', [
            'editUser' => [
                'id' => $user->id,
                'first_name' => $user->first_name ?? '',
                'last_name' => $user->last_name ?? '',
                'email' => $user->email,
                'role' => $user->role ?? 'user',
            ],
            'assignableRoles' => $this->assignableRoles($request),
        ]);
    }

    public function update(Request $request, User $user)
    {
        $this->authorizeSuperAdminTarget($request, $user);

        $assignable = $this->assignableRoles($request);

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['required', 'email', 'unique:users,email,'.$user->id],
            'role' => ['required', 'in:'.implode(',', $assignable)],
        ]);

        // destroy() already refuses to act on your own account; the same has to
        // hold for a role change, or a super admin can demote themselves (or the
        // last remaining super admin) and lock the role out of the install.
        if ($user->id === $request->user()->id && $data['role'] !== $user->role) {
            return back()->with('error', 'You cannot change your own role.');
        }

        if ($user->role === 'super_admin'
            && $data['role'] !== 'super_admin'
            && User::where('role', 'super_admin')->count() <= 1) {
            return back()->with('error', 'This is the last super admin -- promote someone else first.');
        }

        $user->update([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'] ?? null,
            'name' => trim($data['first_name'].' '.($data['last_name'] ?? '')),
            'email' => $data['email'],
            'role' => $data['role'],
        ]);

        return redirect()->route('admin.users.index')->with('success', 'User updated.');
    }

    public function destroy(User $user, Request $request)
    {
        $this->authorizeSuperAdminTarget($request, $user);

        if ($user->id === $request->user()->id) {
            return back()->with('error', 'You cannot delete your own account.');
        }

        $user->delete();

        return redirect()->route('admin.users.index')->with('success', 'User deleted.');
    }

    public function storeInvite(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'role' => ['required', 'in:'.implode(',', $this->assignableRoles($request))],
            'expires_hours' => ['required', 'integer', 'min:1', 'max:720'],
        ]);

        $invite = UserInvite::create([
            'email' => $data['email'],
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'token' => Str::random(64),
            'role' => $data['role'],
            'invited_by' => $request->user()->id,
            'expires_at' => now()->addHours($data['expires_hours']),
        ]);

        Mail::to($invite->email)->queue(new UserInviteMail($invite));

        return back()->with('success', 'Invite sent.');
    }

    public function destroyInvite(UserInvite $invite, Request $request)
    {
        $this->authorizeSuperAdminInvite($request, $invite);

        $invite->delete();

        return back()->with('success', 'Invite revoked.');
    }

    public function resendInvite(Request $request, UserInvite $invite)
    {
        $this->authorizeSuperAdminInvite($request, $invite);

        if ($invite->isUsed()) {
            return back()->with('error', 'Invite has already been used.');
        }

        $data = $request->validate([
            'expires_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
        ]);

        $invite->update([
            'token' => Str::random(64),
            'expires_at' => now()->addHours($data['expires_hours'] ?? 72),
            'invited_by' => $request->user()->id,
        ]);

        Mail::to($invite->email)->queue(new UserInviteMail($invite));

        return back()->with('success', 'Invite resent.');
    }

    public function sendPasswordReset(User $user, Request $request)
    {
        $this->authorizeSuperAdminTarget($request, $user);

        $status = PasswordBroker::sendResetLink(['email' => $user->email]);

        if ($status === PasswordBroker::RESET_LINK_SENT) {
            return back()->with('success', "Password reset email sent to {$user->email}.");
        }

        return back()->with('error', 'Could not send password reset email: '.__($status));
    }

    public function resetTwoFactor(User $user, Request $request)
    {
        $this->authorizeSuperAdminTarget($request, $user);

        if ($user->id === $request->user()->id) {
            return back()->with('error', 'Use your settings to change your own 2FA settings.');
        }

        // Clear TOTP 2FA + passkeys
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $user->passkeys()->delete();

        return back()->with('success', "Two-factor settings reset for {$user->email}.");
    }

    private function authorizeSuperAdminTarget(Request $request, User $user): void
    {
        if ($user->isSuperAdmin() && ! $request->user()?->isSuperAdmin()) {
            abort(403, 'Only a super admin can manage a super admin.');
        }
    }

    private function authorizeSuperAdminInvite(Request $request, UserInvite $invite): void
    {
        if ($invite->role === 'super_admin' && ! $request->user()?->isSuperAdmin()) {
            abort(403, 'Only a super admin can manage a super admin invite.');
        }
    }
}
