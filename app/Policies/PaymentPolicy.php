<?php

namespace App\Policies;

use App\Models\Payment;
use Illuminate\Auth\Access\HandlesAuthorization;

class PaymentPolicy
{
    use HandlesAuthorization;

    public function viewAny($user): bool
    {
        if ($user instanceof \App\Models\Client) return true;
        return $user instanceof \App\Models\User && in_array($user->role, [\App\Models\User::ROLE_SUPER_ADMIN, \App\Models\User::ROLE_ACCOUNT_MANAGER]);
    }

    public function view($user, Payment $payment): bool
    {
        $isClient = $user instanceof \App\Models\Client && $payment->client_id === $user->id;
        $isManager = $user instanceof \App\Models\User && ($user->isSuperAdmin() || $payment->workspace->manager_id === $user->id);
        return $isClient || $isManager;
    }

    public function create($user): bool
    {
        return true;
    }

    public function review($user, Payment $payment): bool
    {
        if (!$user instanceof \App\Models\User) {
            return false;
        }

        // Super admins review anything; an account manager may review payments
        // only inside a workspace they actually manage. Restricting this to
        // super admins alone locked account managers out of their own
        // workspaces (caught by PaymentFlowTest + RealWorldScenarioTest).
        return $user->isSuperAdmin() || $payment->workspace->manager_id === $user->id;
    }
}
