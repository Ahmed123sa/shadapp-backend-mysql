<?php

namespace App\Policies;

use App\Models\Workspace;
use Illuminate\Auth\Access\HandlesAuthorization;

class WorkspacePolicy
{
    use HandlesAuthorization;

    public function view($user, Workspace $workspace): bool
    {
        if ($user instanceof \App\Models\Client && $workspace->client_id === $user->id) return true;
        return $this->isSuperAdmin($user) || $workspace->manager_id === $user->id;
    }

    public function create($user): bool
    {
        return $this->isAccountManager($user);
    }

    public function activate($user, Workspace $workspace): bool
    {
        return $this->isSuperAdmin($user) || $workspace->manager_id === $user->id;
    }

    private function isSuperAdmin($user): bool
    {
        return $user instanceof \App\Models\User && $user->isSuperAdmin();
    }

    private function isAccountManager($user): bool
    {
        return $user instanceof \App\Models\User && $user->isAccountManager();
    }
}
