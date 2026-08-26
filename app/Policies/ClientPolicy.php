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
}
