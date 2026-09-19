<?php

namespace App\Policies;

use App\Models\Contract;
use App\Models\SubUser;
use Illuminate\Auth\Access\HandlesAuthorization;

class ContractPolicy
{
    use HandlesAuthorization;

    // 19 Sept 2026 — viewAny()/view() never had a SubUser branch, so
    // ScopeWorkspace's tenant check passed (the sub-user does belong to the
    // workspace) but this Policy then fell through to Client::false /
    // User::false and 403'd every read. Only ApprovalPolicy had gotten this
    // right; every sibling read policy (this one, Payment, Meeting, File)
    // had the identical gap — a sub-user's entire read path was broken in
    // production while every test here was either negative (tenant
    // isolation) or about a can_* write, so nothing caught it. Per
    // DATA_SAFETY_PLAN.md §7.3, view permissions (can_view_contracts) are
    // UI-only by design — not re-checked here — so a SubUser is allowed
    // through on tenant membership alone, same as Client.
    public function viewAny($user): bool
    {
        if ($user instanceof \App\Models\Client) return true;
        if ($user instanceof SubUser) return true;
        return $user instanceof \App\Models\User && in_array($user->role, [\App\Models\User::ROLE_SUPER_ADMIN, \App\Models\User::ROLE_ACCOUNT_MANAGER]);
    }

    public function view($user, Contract $contract): bool
    {
        $isClient = $user instanceof \App\Models\Client && $contract->workspace->client_id === $user->id;
        $isSubUser = $user instanceof SubUser && $contract->workspace->client_id === $user->client_id;
        $isManager = $user instanceof \App\Models\User && ($user->isSuperAdmin() || $contract->workspace->manager_id === $user->id);
        return $isClient || $isSubUser || $isManager;
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
