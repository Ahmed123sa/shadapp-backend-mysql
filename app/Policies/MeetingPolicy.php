<?php

namespace App\Policies;

use App\Models\Meeting;
use Illuminate\Auth\Access\HandlesAuthorization;

class MeetingPolicy
{
    use HandlesAuthorization;

    public function viewAny($user): bool
    {
        if ($user instanceof \App\Models\Client) return true;
        return $user instanceof \App\Models\User && in_array($user->role, [\App\Models\User::ROLE_SUPER_ADMIN, \App\Models\User::ROLE_ACCOUNT_MANAGER]);
    }

    public function view($user, Meeting $meeting): bool
    {
        return $user instanceof \App\Models\User && ($user->isSuperAdmin() || $meeting->workspace->manager_id === $user->id);
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
