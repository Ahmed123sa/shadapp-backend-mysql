<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\SubUser;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Sub-users are managed by their owning client alone — see SUBUSER_PLAN.md
 * §1.2. No User (account manager or super admin) branch appears anywhere in
 * this policy on purpose: that access existed until 18 Sept 2026 (two
 * inconsistent variants — view()/updateProfile() let in *any* manager for
 * *any* client, while delete()/updatePermissions() required the specific
 * manager and even blocked the super admin) and was reachable over the API
 * with no dashboard or mobile screen ever exercising it. It has been removed
 * rather than narrowed.
 */
class SubUserPolicy
{
    use HandlesAuthorization;

    /**
     * The route has no {workspace} segment, so ScopeWorkspace middleware
     * never runs on it — this check against the target $client is the only
     * thing standing between one client and planting an account inside
     * another client's tenant.
     */
    public function create($user, Client $client): bool
    {
        return $user instanceof Client && $user->id === $client->id;
    }

    public function view($user, SubUser $subUser): bool
    {
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
        return $user instanceof Client && $subUser->client_id === $user->id;
    }

    public function updatePermissions($user, SubUser $subUser): bool
    {
        return $user instanceof Client && $subUser->client_id === $user->id;
    }

    public function updateProfile($user, SubUser $subUser): bool
    {
        if ($user instanceof Client) {
            return $subUser->client_id === $user->id;
        }
        if ($user instanceof SubUser) {
            return $user->id === $subUser->id;
        }
        return false;
    }
}
