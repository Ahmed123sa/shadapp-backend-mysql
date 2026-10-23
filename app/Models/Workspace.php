<?php

namespace App\Models;

use App\Models\FileEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Workspace extends Model
{
    use HasFactory;

    protected $fillable = ['client_id', 'manager_id', 'status', 'activated_at'];

    protected function casts(): array
    {
        return ['activated_at' => 'datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /** Company staff members assigned to this client (workspace_staff). */
    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_staff')
            ->withPivot('assigned_by', 'permissions')
            ->withTimestamps();
    }

    /** Decode a workspace_staff.permissions value into the full {key: bool} map (null = all allowed). */
    public static function decodeStaffPermissions($raw): array
    {
        $arr = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);

        return User::staffPermissionMap(is_array($arr) ? $arr : null);
    }

    /** Full permission map of an assigned staff member on this client (all true when none stored). */
    public function staffPermissionsOf($user): array
    {
        $row = $this->staff()->whereKey($user->getKey())->first();

        return self::decodeStaffPermissions($row?->pivot?->permissions);
    }

    /** Whether this assigned, active staff member holds $key on this client. */
    public function staffCan($user, string $key): bool
    {
        return $this->hasStaffMember($user)
            && (self::decodeStaffPermissions($this->staff()->whereKey($user->getKey())->first()?->pivot?->permissions)[$key] ?? false);
    }

    /**
     * Whether this user is an active staff member assigned to the workspace.
     * Un-assigning or archiving takes effect on the very next request.
     */
    public function hasStaffMember($user): bool
    {
        return $user instanceof User
            && $user->isStaffMember()
            && $user->isActive()
            && $this->staff()->whereKey($user->getKey())->exists();
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class);
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(Meeting::class);
    }

    public function chatMessages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(FileEntry::class);
    }

    public function documentDefinitions(): HasMany
    {
        return $this->hasMany(DocumentDefinition::class);
    }

    /**
     * True once the owning client has been archived (Client::status ===
     * 'archived' — see DATA_SAFETY_PLAN.md §2.3). An archived client's
     * workspace stays fully readable (contracts, payments, chat history,
     * files) but every store()-style action that would create new activity
     * in it must check this first and refuse.
     */
    public function isClientArchived(): bool
    {
        return (bool) $this->client?->isFrozen();
    }

    /**
     * Single source of truth for "does this authenticated principal belong
     * to this workspace's tenant boundary". Used by ScopeWorkspace middleware
     * for every {workspace}-bound route, and must also be called explicitly
     * by any controller action that receives a workspace-scoped child
     * resource (e.g. ChatMessage) without {workspace} itself in the route.
     */
    public function canBeAccessedBy($user): bool
    {
        return match (true) {
            $user instanceof User => $user->seesAllFinance()
                // A staff member's ownerManagerId() is their own id, which
                // matches no workspace; they get in only through assignment.
                || ($user->isStaffMember() ? $this->hasStaffMember($user) : $this->manager_id === $user->ownerManagerId()),
            $user instanceof Client => $this->client_id === $user->id,
            $user instanceof SubUser => $this->client_id === $user->client_id,
            default => false,
        };
    }
}
