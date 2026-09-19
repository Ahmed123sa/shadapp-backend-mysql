<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class SubUser extends Authenticatable
{
    use HasFactory, HasApiTokens, Notifiable;

    /**
     * The single source for the permission keys a sub-user can be granted.
     * SUBUSER_PLAN.md §6.1 — this same 11-item list used to be typed out by
     * hand in three places (this class, subusers_page.dart on mobile, and
     * ClientSubUsers.tsx on the dashboard), so adding or renaming a
     * permission meant remembering to update all three or silently
     * desyncing them. SubUserController::updatePermissions() validates
     * against this, and GET /sub-user-permissions hands it to both
     * frontends so they no longer hardcode their own copy.
     */
    public const PERMISSION_KEYS = [
        'can_chat', 'can_view_contracts', 'can_approve_contracts',
        'can_view_payments', 'can_upload_payment_proof',
        'can_view_approvals', 'can_respond_approvals',
        'can_view_files', 'can_upload_files',
        'can_view_meetings', 'can_join_meetings',
    ];

    protected $fillable = ['name', 'email', 'password', 'client_id', 'permissions', 'avatar_url', 'phone', 'date_of_birth'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'permissions' => 'array',
            'date_of_birth' => 'date',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function hasPermission(string $key): bool
    {
        $permissions = $this->permissions ?? [];
        return $permissions[$key] ?? false;
    }

    public function getPermissionsArray(): array
    {
        return $this->permissions ?? [];
    }

    /**
     * Signs the stored URL fresh on every read — see App\Support\FileUrl.
     */
    public function getAvatarUrlAttribute($value): ?string
    {
        return \App\Support\FileUrl::sign($value);
    }
}
