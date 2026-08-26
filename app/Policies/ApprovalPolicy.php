<?php

namespace App\Policies;

use App\Models\Approval;
use Illuminate\Auth\Access\HandlesAuthorization;

class ApprovalPolicy
{
    use HandlesAuthorization;

    public function viewAny($user): bool
    {
        if ($user instanceof \App\Models\Client) return true;
        if ($user instanceof \App\Models\SubUser) return true;
        return $user instanceof \App\Models\User && in_array($user->role, [\App\Models\User::ROLE_SUPER_ADMIN, \App\Models\User::ROLE_ACCOUNT_MANAGER]);
    }

    public function view($user, Approval $approval): bool
    {
        return $user instanceof \App\Models\User && ($user->isSuperAdmin() || $approval->workspace->manager_id === $user->id);
    }

    public function create($user): bool
    {
        return $user instanceof \App\Models\User && in_array($user->role, [\App\Models\User::ROLE_SUPER_ADMIN, \App\Models\User::ROLE_ACCOUNT_MANAGER]);
    }

    public function respond($user, Approval $approval): bool
    {
        if (!$user instanceof \App\Models\User) {
            return false;
        }
        if ($approval->requested_by === $user->id) {
            return false;
        }
        return $user->isSuperAdmin() || $approval->workspace->manager_id === $user->id;
    }
}
