<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'phone', 'password', 'role', 'super_admin_id', 'official_email', 'signature_data', 'signed_at', 'avatar_url', 'date_of_birth', 'is_active', 'deactivated_at', 'parent_manager_id', 'assistant_permissions', 'deactivated_by_parent'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    const ROLE_SUPER_ADMIN = 'super_admin';
    const ROLE_ACCOUNT_MANAGER = 'account_manager';
    const ROLE_MANAGER_ASSISTANT = 'manager_assistant';

    /**
     * Single source for the permissions a manager can give an assistant
     * (MANAGER_ASSISTANT_PLAN.md §4.3). can_view_clients is always on.
     * Payments / finance are deliberately NOT here: they are forbidden to
     * assistants outright, whatever the manager picks.
     */
    public const ASSISTANT_PERMISSION_KEYS = [
        'can_view_clients', 'can_edit_clients', 'can_chat',
        'can_manage_contracts', 'can_manage_meetings',
        'can_view_files', 'can_review_files', 'can_manage_approvals',
    ];

    protected $appends = ['signature_url'];

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
            'is_active' => 'boolean',
            'deactivated_by_parent' => 'boolean',
            'assistant_permissions' => 'array',
            'deactivated_at' => 'datetime',
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

    /**
     * The account manager whose clients this user may see. For a manager
     * that is themselves. Every query that scopes by "my clients" must use
     * this instead of $user->id, and must scope by it for EVERY staff user
     * who is not a super admin (closed by default) — never the other way
     * round, or a new staff role would silently inherit super-admin reach.
     */
    public function ownerManagerId(): int
    {
        if ($this->isAssistant()) {
            // An assistant with no manager owns nothing: -1 matches no row.
            return (int) ($this->parent_manager_id ?? -1);
        }

        return (int) $this->id;
    }

    public function isAssistant(): bool
    {
        return $this->role === self::ROLE_MANAGER_ASSISTANT;
    }

    public function parentManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'parent_manager_id');
    }

    public function assistants(): HasMany
    {
        return $this->hasMany(User::class, 'parent_manager_id');
    }

    /**
     * Whether this user may perform the action behind $key. Anyone who is
     * not an assistant is never limited by assistant permissions (they have
     * their own role rules); an assistant needs the flag, and
     * can_view_clients is always granted.
     */
    public function assistantCan(string $key): bool
    {
        if (! $this->isAssistant()) {
            return true;
        }
        if ($key === 'can_view_clients') {
            return true;
        }

        return (bool) (($this->assistant_permissions ?? [])[$key] ?? false);
    }

    /**
     * True for every account with no deactivation history — including super
     * admins, who don't go through the deactivate/activate flow at all.
     * Only account managers can ever have is_active === false.
     */
    public function isActive(): bool
    {
        if ($this->isAssistant()) {
            // Needs both: switched on itself AND its manager still active.
            return (bool) $this->is_active && (bool) $this->parentManager?->is_active;
        }

        return (bool) $this->is_active;
    }

    /**
     * Scopes queries to accounts that haven't been deactivated. Used
     * anywhere a "who can this be assigned to / notified / picked from a
     * list" question is asked — see DATA_SAFETY_PLAN.md §2.2.5 for the full
     * list of call sites that must respect this.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
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

    /**
     * 23 Sept 2026 — a signed, displayable URL for an uploaded signature
     * image (null for a typed signature). signature_data itself stays the
     * raw stored value, because it's copied verbatim into permanent snapshot
     * columns (see getAvatarUrlAttribute above); the apps use this field only
     * to *show* the image. Without it, the preview pointed at /storage/...,
     * which doesn't exist in production (no storage:link — see README).
     */
    public function getSignatureUrlAttribute(): ?string
    {
        $value = $this->attributes['signature_data'] ?? null;

        return \App\Support\SignatureValue::isImage($value) ? \App\Support\FileUrl::sign($value) : null;
    }
}
