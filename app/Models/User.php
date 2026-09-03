<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'phone', 'password', 'role', 'super_admin_id', 'official_email', 'signature_data', 'signed_at', 'avatar_url', 'date_of_birth'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    const ROLE_SUPER_ADMIN = 'super_admin';
    const ROLE_ACCOUNT_MANAGER = 'account_manager';

    /**
     * Overrides the framework default, which sends an English message with a
     * link built from APP_URL — i.e. pointing at this API, not at the
     * dashboard the user actually needs to open.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new \App\Notifications\ResetPasswordNotification($token, 'staff'));
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'signed_at' => 'datetime',
            'date_of_birth' => 'date',
        ];
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN;
    }

    public function isAccountManager(): bool
    {
        return $this->role === self::ROLE_ACCOUNT_MANAGER;
    }

    public function managedAccounts(): HasMany
    {
        return $this->hasMany(User::class, 'super_admin_id');
    }

    public function managedClients(): HasMany
    {
        return $this->hasMany(Client::class, 'manager_id');
    }

    /**
     * Signs the stored URL fresh on every read — see App\Support\FileUrl.
     * signature_data is deliberately left unsigned; see Client::getAvatarUrlAttribute
     * for why (it's copied verbatim into permanent snapshot columns elsewhere).
     */
    public function getAvatarUrlAttribute($value): ?string
    {
        return \App\Support\FileUrl::sign($value);
    }
}
