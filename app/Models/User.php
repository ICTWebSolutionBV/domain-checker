<?php

namespace App\Models;

use App\Notifications\QueuedResetPassword;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\LaravelPasskeys\Models\Concerns\HasPasskeys;
use Spatie\LaravelPasskeys\Models\Concerns\InteractsWithPasskeys;
use Spatie\LaravelPasskeys\Models\Passkey;

#[Fillable(['name', 'first_name', 'last_name', 'email', 'password', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements HasPasskeys
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable, InteractsWithPasskeys;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Queue the reset mail instead of sending it inside the request: the
     * framework's ResetPassword notification is not queueable, so both the
     * public forgot-password form and the admin action blocked on SMTP.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new QueuedResetPassword($token));
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, ['admin', 'super_admin'], true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function hasTotpEnabled(): bool
    {
        return ! is_null($this->two_factor_secret) && ! is_null($this->two_factor_confirmed_at);
    }

    /**
     * Prefer a count loaded by withCount('passkeys'): calling this per row is
     * one query per user, which is where the admin index's N+1 came from.
     */
    public function hasPasskeysEnabled(): bool
    {
        if (! is_null($this->passkeys_count)) {
            return $this->passkeys_count > 0;
        }

        return $this->passkeys()->exists();
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->hasTotpEnabled() || $this->hasPasskeysEnabled();
    }
}
