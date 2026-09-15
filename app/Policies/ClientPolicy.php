<?php

namespace App\Policies;

use App\Models\Client;
use Illuminate\Auth\Access\HandlesAuthorization;

class ClientPolicy
{
    use HandlesAuthorization;

    public function viewAny($user): bool
    {
        return $user instanceof \App\Models\User && in_array($user->role, [\App\Models\User::ROLE_SUPER_ADMIN, \App\Models\User::ROLE_ACCOUNT_MANAGER]);
    }

    public function view($user, Client $client): bool
    {
        if ($user instanceof \App\Models\Client && $user->id === $client->id) {
            return true;
        }
        if ($user instanceof \App\Models\SubUser && $user->client_id === $client->id) {
            return true;
        }
        return $user instanceof \App\Models\User && ($user->isSuperAdmin() || $client->manager_id === $user->id);
    }

    public function create($user): bool
    {
        return $user instanceof \App\Models\User && $user->isAccountManager();
    }

    public function update($user, Client $client): bool
    {
        if ($user instanceof \App\Models\Client && $user->id === $client->id) {
            return true;
        }
        return $user instanceof \App\Models\User && ($user->isSuperAdmin() || $client->manager_id === $user->id);
    }

    public function delete($user, Client $client): bool
    {
        return $user instanceof \App\Models\User && ($user->isSuperAdmin() || $client->manager_id === $user->id);
    }

    // Super admin only — reassigning a client's manager is a whole-account
    // operation, not something a manager should be able to do to their own
    // clients (or, worse, someone else's, if the request forged an id).
    public function transfer($user, Client $client): bool
    {
        return $user instanceof \App\Models\User && $user->isSuperAdmin();
    }

    // Super admin, or the account manager this client is currently assigned
    // to — same shape as update()/delete() above, but kept as its own method
    // (rather than reusing update()) because update() also grants the client
    // itself edit access, and a client must never be able to archive or
    // unarchive its own account. See DATA_SAFETY_PLAN.md §5.2.
    public function archive($user, Client $client): bool
    {
        return $user instanceof \App\Models\User && ($user->isSuperAdmin() || $client->manager_id === $user->id);
    }
}
