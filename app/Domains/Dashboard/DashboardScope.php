<?php

namespace App\Domains\Dashboard;

use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Payment;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;

/**
 * Scopes a query to what a user is allowed to see for reporting/dashboard
 * purposes: an account manager to their own clients, a super admin to
 * everyone (optionally narrowed by manager_id or the other filters below).
 *
 * 24 Sept 2026 — extracted verbatim from AuditController::reports()'s
 * private query builders, which used to be the only place this scoping
 * existed. DashboardController::stats() (added the same day, see
 * server-side-stats-plan.md) needs the identical scoping, so it's shared
 * here instead of being re-derived and drifting apart the way the
 * dashboard's own client-side counts and the /badge-counts approvals badge
 * already had (mismatched "pending approvals" definitions across the web,
 * mobile, and the badge — see pendingApprovalsTotal() below).
 *
 * This is a pure extraction: every method here reproduces the corresponding
 * private method AuditController used to have, unchanged, so /reports keeps
 * behaving exactly as before.
 */
class DashboardScope
{
    public static function applyDateRange(Builder $q, array $filters, string $column = 'created_at'): void
    {
        if (!empty($filters['date_from'])) {
            $q->whereDate($column, '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $q->whereDate($column, '<=', $filters['date_to']);
        }
    }

    public static function applyClientFilters(Builder $q, array $filters): void
    {
        if (!empty($filters['client_id'])) {
            $q->where('client_id', $filters['client_id']);
        }
    }

    public static function clients(bool $isAm, $user, array $filters = []): Builder
    {
        $q = Client::query();
        if ($isAm) $q->where('manager_id', $user->id);
        if (!empty($filters['manager_id']) && $user->isSuperAdmin()) {
            $q->where('manager_id', $filters['manager_id']);
        }
        if (!empty($filters['client_type'])) {
            $q->where('client_type', $filters['client_type']);
        }
        if (!empty($filters['country'])) {
            $q->where('country', $filters['country']);
        }
        if (!empty($filters['industry'])) {
            $q->where('industry', $filters['industry']);
        }
        self::applyDateRange($q, $filters);
        return $q;
    }

    public static function workspaces(bool $isAm, $user, array $filters = []): Builder
    {
        $q = Workspace::query();
        if ($isAm) $q->whereHas('client', fn ($cq) => $cq->where('manager_id', $user->id));
        if (!empty($filters['client_id'])) {
            $q->where('client_id', $filters['client_id']);
        }
        self::applyDateRange($q, $filters);
        return $q;
    }

    public static function contracts(bool $isAm, $user, array $filters = []): Builder
    {
        $q = Contract::query();
        if ($isAm) $q->whereHas('workspace.client', fn ($cq) => $cq->where('manager_id', $user->id));
        if (!empty($filters['client_id'])) {
            $q->whereHas('workspace', fn ($wq) => $wq->where('client_id', $filters['client_id']));
        }
        self::applyDateRange($q, $filters);
        return $q;
    }

    public static function payments(bool $isAm, $user, array $filters = []): Builder
    {
        $q = Payment::query();
        if ($isAm) $q->whereHas('client', fn ($cq) => $cq->where('manager_id', $user->id));
        self::applyClientFilters($q, $filters);
        self::applyDateRange($q, $filters);
        return $q;
    }

    public static function approvals(bool $isAm, $user, array $filters = []): Builder
    {
        $q = Approval::query();
        if ($isAm) $q->whereHas('workspace.client', fn ($cq) => $cq->where('manager_id', $user->id));
        if (!empty($filters['client_id'])) {
            $q->whereHas('workspace', fn ($wq) => $wq->where('client_id', $filters['client_id']));
        }
        self::applyDateRange($q, $filters);
        return $q;
    }

    public static function auditLogs(bool $isAm, $user, array $filters = []): Builder
    {
        $q = AuditLog::query();
        if ($isAm) {
            $clientIds = $user->managedClients()->pluck('id');
            $q->where(function ($q) use ($user, $clientIds) {
                $q->where('user_id', $user->id)
                  ->orWhereIn('client_id', $clientIds)
                  ->orWhereIn('auditable_id', $clientIds);
            });
        }
        if (!empty($filters['client_id'])) {
            $q->where('client_id', $filters['client_id']);
        }
        self::applyDateRange($q, $filters);
        return $q;
    }

    /**
     * "Approvals awaiting action" as the mobile Approvals screen
     * (sa_approvals_page.dart / the AM equivalent) and its badge
     * (DashboardController::amCounts/saCounts) both define it: pending
     * approval requests, contracts sent to the client awaiting a response,
     * and payments awaiting review. amCounts()/saCounts() and
     * DashboardController::stats() both call this so the badge and the
     * dashboard card can't drift apart again the way they already had (23
     * Sept 2026 — the badge used to omit payments; a web dashboard card
     * separately omitted approval requests).
     *
     * @return array{pending_requests: int, pending_contracts: int, pending_payments: int, total: int}
     */
    public static function pendingApprovalsTotal(bool $isAm, $user, array $filters = []): array
    {
        $pendingRequests = self::approvals($isAm, $user, $filters)->where('status', 'pending')->count();
        $pendingContracts = self::contracts($isAm, $user, $filters)->whereIn('status', ['sent', 'client_approved'])->count();
        $pendingPayments = self::payments($isAm, $user, $filters)->where('status', 'pending')->count();

        return [
            'pending_requests' => $pendingRequests,
            'pending_contracts' => $pendingContracts,
            'pending_payments' => $pendingPayments,
            'total' => $pendingRequests + $pendingContracts + $pendingPayments,
        ];
    }
}
