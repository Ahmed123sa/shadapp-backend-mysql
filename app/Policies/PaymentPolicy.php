<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\SubUser;
use Illuminate\Auth\Access\HandlesAuthorization;

class PaymentPolicy
{
    use HandlesAuthorization;

    // 19 Sept 2026 — see the identical note in ContractPolicy: no SubUser
    // branch meant every sub-user payment read 403'd in production despite
    // passing ScopeWorkspace's tenant check. can_view_payments stays
    // UI-only per DATA_SAFETY_PLAN.md §7.3 — tenant membership is enough.
    public function viewAny($user): bool
    {
        if ($user instanceof \App\Models\Client) return true;
        if ($user instanceof SubUser) return true;
        return $user instanceof \App\Models\User && in_array($user->role, [\App\Models\User::ROLE_SUPER_ADMIN, \App\Models\User::ROLE_ACCOUNT_MANAGER, \App\Models\User::ROLE_ACCOUNTANT]);
    }

    public function view($user, Payment $payment): bool
    {
        $isClient = $user instanceof \App\Models\Client && $payment->client_id === $user->id;
        $isSubUser = $user instanceof SubUser && $payment->client_id === $user->client_id;
        $isManager = $user instanceof \App\Models\User && ($user->seesAllFinance() || $payment->workspace->manager_id === $user->id);
        return $isClient || $isSubUser || $isManager;
    }

    public function create($user): bool
    {
        // The accountant reviews money, they never create or edit it.
        return ! ($user instanceof \App\Models\User && $user->isAccountant());
    }

    /**
     * Approving or rejecting a proof is the super admin's and the accountant's
     * job only. An account manager (and an assistant or staff member) never
     * reviews payments — the apps never offered it and the API no longer does
     * either (ACCOUNTANT_ROLE_PLAN.md §٩.٢).
     */
    public function review($user, Payment $payment): bool
    {
        return $user instanceof \App\Models\User && $user->canReviewPayments();
    }
}

