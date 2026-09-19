<?php

namespace App\Policies;

use App\Models\FileEntry;
use App\Models\SubUser;
use Illuminate\Auth\Access\HandlesAuthorization;

class FileEntryPolicy
{
    use HandlesAuthorization;

    // 19 Sept 2026 — same SubUser-branch gap as the other read policies
    // (can_view_files stays UI-only per DATA_SAFETY_PLAN.md §7.3).
    public function viewAny($user): bool
    {
        if ($user instanceof \App\Models\Client) return true;
        if ($user instanceof SubUser) return true;
        return $user instanceof \App\Models\User && in_array($user->role, [\App\Models\User::ROLE_SUPER_ADMIN, \App\Models\User::ROLE_ACCOUNT_MANAGER]);
    }
}
