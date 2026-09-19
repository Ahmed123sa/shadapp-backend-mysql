<?php

namespace App\Policies;

use App\Models\Meeting;
use App\Models\SubUser;
use Illuminate\Auth\Access\HandlesAuthorization;

class MeetingPolicy
{
    use HandlesAuthorization;

    // 19 Sept 2026 — same SubUser-branch gap as ContractPolicy/PaymentPolicy
    // (can_view_meetings stays UI-only per DATA_SAFETY_PLAN.md §7.3, tenant
    // membership is enough here). view() had a second, older bug on top:
    // it only ever returned true for a User, so the owning Client couldn't
    // open a single meeting either (only viewAny() let them list them) —
    // fixed alongside this.
    public function viewAny($user): bool
    {
        if ($user instanceof \App\Models\Client) return true;
        if ($user instanceof SubUser) return true;
        return $user instanceof \App\Models\User && in_array($user->role, [\App\Models\User::ROLE_SUPER_ADMIN, \App\Models\User::ROLE_ACCOUNT_MANAGER]);
    }

    public function view($user, Meeting $meeting): bool
    {
        $isClient = $user instanceof \App\Models\Client && $meeting->workspace->client_id === $user->id;
        $isSubUser = $user instanceof SubUser && $meeting->workspace->client_id === $user->client_id;
        $isManager = $user instanceof \App\Models\User && ($user->isSuperAdmin() || $meeting->workspace->manager_id === $user->id);
        return $isClient || $isSubUser || $isManager;
    }

    public function create($user): bool
    {
        return $user instanceof \App\Models\User && $user->isAccountManager();
    }

    public function update($user, Meeting $meeting): bool
    {
        if ($user instanceof \App\Models\Client) return false;
        return $user instanceof \App\Models\User && ($user->isSuperAdmin() || $meeting->workspace->manager_id === $user->id);
    }

    public function delete($user, Meeting $meeting): bool
    {
        if ($user instanceof \App\Models\Client) return false;
        return $user instanceof \App\Models\User && ($user->isSuperAdmin() || $meeting->workspace->manager_id === $user->id);
    }
}
