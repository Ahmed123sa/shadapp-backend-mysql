<?php

namespace App\Policies;

use App\Models\Contract;
use Illuminate\Auth\Access\HandlesAuthorization;

class ContractPolicy
{
    use HandlesAuthorization;

    public function viewAny($user): bool
    {
        if ($user instanceof \App\Models\Client) return true;
        return $user instanceof \App\Models\User && in_array($user->role, [\App\Models\User::ROLE_SUPER_ADMIN, \App\Models\User::ROLE_ACCOUNT_MANAGER]);
    }

    public function view($user, Contract $contract): bool
    {
        $isClient = $user instanceof \App\Models\Client && $contract->workspace->client_id === $user->id;
        $isManager = $user instanceof \App\Models\User && ($user->isSuperAdmin() || $contract->workspace->manager_id === $user->id);
        return $isClient || $isManager;
    }

    public function create($user): bool
    {
        return $user instanceof \App\Models\User && $user->isAccountManager();
    }

    public function update($user, Contract $contract): bool
    {
        return $user instanceof \App\Models\User && ($user->isSuperAdmin() || $contract->workspace->manager_id === $user->id);
    }

    public function delete($user, Contract $contract): bool
    {
        return $user instanceof \App\Models\User && ($user->isSuperAdmin() || $contract->workspace->manager_id === $user->id);
    }

    public function send($user, Contract $contract): bool
    {
        return $user instanceof \App\Models\User && $contract->workspace->manager_id === $user->id;
    }

    public function companyApprove($user, Contract $contract): bool
    {
        return $user instanceof \App\Models\User && ($user->isSuperAdmin() || $contract->workspace->manager_id === $user->id);
    }

    public function complete($user, Contract $contract): bool
    {
        return $user instanceof \App\Models\User && ($user->isSuperAdmin() || $contract->workspace->manager_id === $user->id);
    }

    public function archive($user, Contract $contract): bool
    {
        return $user instanceof \App\Models\User && ($user->isSuperAdmin() || $contract->workspace->manager_id === $user->id);
    }
}
