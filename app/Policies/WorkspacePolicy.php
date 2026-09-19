<?php

namespace App\Policies;

use App\Models\SubUser;
use App\Models\Workspace;
use Illuminate\Auth\Access\HandlesAuthorization;

class WorkspacePolicy
{
    use HandlesAuthorization;

    // 19 Sept 2026 — this had no SubUser branch, so every sub-user request
    // for their own workspace (and everything ScopeWorkspace lets through
    // beneath it: contracts, payments, meetings, files, chat) 403'd from
    // this Policy specifically — ScopeWorkspace's own tenant check passed
    // fine. Confirmed live: the mobile console showed "This action is
    // unauthorized" (Laravel's default Policy-denial message, not
    // ScopeWorkspace's Arabic one) on /api/workspaces/{id} itself.
    //
    // Also fixed a second, separate bug on the fallback line: it compared
    // $workspace->manager_id (a users.id) against $user->id unconditionally
    // for *any* non-super-admin, non-owning-Client caller — including a
    // SubUser, whose id is a sub_users.id, not a users.id. Coincidentally
    // harmless before now only because a SubUser could never reach this
    // line without an earlier abort, but explicit typing is correct
    // regardless. Per DATA_SAFETY_PLAN.md §7.3, can_view_* stays UI-only,
    // so tenant membership alone is enough here, same as Client.
    public function view($user, Workspace $workspace): bool
    {
        if ($user instanceof \App\Models\Client && $workspace->client_id === $user->id) return true;
        if ($user instanceof SubUser && $workspace->client_id === $user->client_id) return true;
        return $this->isSuperAdmin($user) || ($user instanceof \App\Models\User && $workspace->manager_id === $user->id);
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
