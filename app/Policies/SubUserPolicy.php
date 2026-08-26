<?php

namespace App\Policies;

use App\Models\SubUser;
use App\Models\User;
use App\Models\Client;
use Illuminate\Auth\Access\HandlesAuthorization;

class SubUserPolicy
{
    use HandlesAuthorization;

    public function create($user): bool
    {
        if ($user instanceof User) {
            return $user->isAccountManager();
        }
        if ($user instanceof Client) {
            return true;
        }
        if ($user instanceof SubUser) {
            return false;
        }
        return false;
    }

    public function view($user, SubUser $subUser): bool
    {
        if ($user instanceof User) {
            return true;
        }
        if ($user instanceof Client) {
            return $subUser->client_id === $user->id;
        }
        if ($user instanceof SubUser) {
            return $user->id === $subUser->id;
        }
        return false;
    }

    public function delete($user, SubUser $subUser): bool
    {
        if ($user instanceof User) {
            return $subUser->client->manager_id === $user->id;
        }
        if ($user instanceof Client) {
            return $subUser->client_id === $user->id;
        }
        return false;
    }

    public function updatePermissions($user, SubUser $subUser): bool
    {
        if ($user instanceof User) {
            return $subUser->client->manager_id === $user->id;
        }
        if ($user instanceof Client) {
            return $subUser->client_id === $user->id;
        }
        return false;
    }

    public function updateProfile($user, SubUser $subUser): bool
    {
        if ($user instanceof User) {
            return true;
        }
        if ($user instanceof Client) {
            return $subUser->client_id === $user->id;
        }
        if ($user instanceof SubUser) {
            return $user->id === $subUser->id;
        }
        return false;
    }
}
