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
     * plans/notifications-badges-toasts-plan.md ن2/ح2ب — the single gate for
     * whether this sub-user is allowed to see a notification of the given
     * type in GET /notifications, and (ح2ب) whether it should receive push
     * for it via FcmChannel. Lives here, not in NotificationController or
     * FcmChannel, so both reuse the exact same type→permission map instead
     * of drifting apart. $type is the canonical value from a notification's
     * toDatabase()['type'] (`contract_`, `payment_`, `approval_`, `meeting_`
     * prefixes, or `chat`), not toFcm()'s data.type, which uses inconsistent
     * naming (ن4).
     */
    public function canSeeNotificationType(?string $type): bool
    {
        if ($type === null) {
            return true;
        }
        if ($type === 'chat') {
            return $this->hasPermission('can_chat');
        }
        if (str_starts_with($type, 'contract')) {
            return $this->hasPermission('can_view_contracts');
        }
        if (str_starts_with($type, 'payment') || $type === 'workspace_activated') {
            return $this->hasPermission('can_view_payments');
        }
        if (str_starts_with($type, 'approval')) {
            return $this->hasPermission('can_view_approvals');
        }
        if (str_starts_with($type, 'meeting')) {
            return $this->hasPermission('can_view_meetings');
        }
        return true;
    }

    /**
     * Signs the stored URL fresh on every read — see App\Support\FileUrl.
     */
    public function getAvatarUrlAttribute($value): ?string
    {
        return \App\Support\FileUrl::sign($value);
    }
}
