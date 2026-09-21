<?php

namespace App\Domains\Audit;

use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class AuditController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = AuditLog::with('user', 'client', 'auditable');

        if ($request->filled('action')) {
            $query->where('action', 'like', $request->action . '%');
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('client_id')) {
            $query->where('client_id', $request->client_id);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $user = $request->user();
        if ($user->isAccountManager()) {
            $clientIds = $user->managedClients()->pluck('id');
            $query->where(function ($q) use ($user, $clientIds) {
                $q->where('user_id', $user->id)
                  ->orWhereIn('client_id', $clientIds)
                  ->orWhereIn('auditable_id', $clientIds);
            });
        }

        return response()->json([
            'logs' => $query->latest()->paginate(25),
        ]);
    }

    public function reports(Request $request): JsonResponse
    {
        $user = $request->user();
        $isAm = $user->isAccountManager();

        $filters = $request->only([
            'date_from', 'date_to', 'client_id', 'manager_id',
            'client_type', 'country', 'industry',
        ]);

        $data = [
            'total_clients' => (clone $this->clientQuery($isAm, $user, $filters))->count(),
            'active_workspaces' => (clone $this->workspaceQuery($isAm, $user, $filters))->where('status', 'active')->count(),
            'pending_payments' => (clone $this->paymentQuery($isAm, $user, $filters))->where('status', 'pending')->count(),
            'pending_approvals' => (clone $this->approvalQuery($isAm, $user, $filters))->where('status', 'pending')->count(),
            'recent_logins' => (clone $this->auditLogQuery($isAm, $user, $filters))->where('action', 'login')->whereDate('created_at', today())->count(),
            'contracts_by_status' => (clone $this->contractQuery($isAm, $user, $filters))
                ->selectRaw('status, count(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray(),
            // 21 Sept 2026 — `where('status', 'approved')` was missing, so
            // this counted pending and rejected payments as revenue. Both
            // clients already present it as approved-only: the dashboard
            // labels it "Monthly Revenue" and the mobile AM reports tab
            // labels it reportsMonthlyRevenue with the subtitle
            // reportsAcceptedPaymentsTotal ("total accepted payments").
            // The backend simply never enforced what both of them claim,
            // so this aligns it with PaymentController's own definition of
            // revenue in approved_by_currency. Expect reported figures to
            // drop; the previous ones included money that was never
            // collected.
            //
            // This still SUMs across currencies, so SAR/USD/EGP land in one
            // number — kept exactly as-is (shape and all) because mobile's
            // reports_tab and manager_detail_page still read it, and an old
            // app build in the field would break if this key changed shape.
            // payments_by_month_by_currency below is the real fix; this key
            // stays for backward compatibility until those mobile call
            // sites are migrated.
            'payments_by_month' => (clone $this->paymentQuery($isAm, $user, $filters))
                ->where('status', 'approved')
                ->selectRaw(\App\Support\DbExpr::yearMonth('created_at') . ' as month, SUM(amount) as total')
                ->groupBy('month')
                ->pluck('total', 'month')
                ->toArray(),
            // 21 Sept 2026 — added so a consumer can show revenue per
            // currency instead of a meaningless cross-currency sum labelled
            // with whichever currency happened to be hardcoded (the
            // dashboard's Reports page said "EGP" on every figure
            // regardless of what was actually paid). Same approved-only
            // rule and same query as payments_by_month above, just grouped
            // by currency too: { "2026-09": { "SAR": 5000, "USD": 3000 } }.
            'payments_by_month_by_currency' => (clone $this->paymentQuery($isAm, $user, $filters))
                ->where('status', 'approved')
                ->selectRaw(\App\Support\DbExpr::yearMonth('created_at') . ' as month, currency, SUM(amount) as total')
                ->groupBy('month', 'currency')
                ->get()
                ->groupBy('month')
                ->map(fn ($rows) => $rows->pluck('total', 'currency'))
                ->toArray(),
            'approval_stats' => [
                'approved' => (clone $this->approvalQuery($isAm, $user, $filters))->where('status', 'approved')->count(),
                'rejected' => (clone $this->approvalQuery($isAm, $user, $filters))->where('status', 'rejected')->count(),
                'pending' => (clone $this->approvalQuery($isAm, $user, $filters))->where('status', 'pending')->count(),
            ],
            // 21 Sept 2026 — this key never existed, so both the dashboard's
            // and mobile's "top managers" leaderboard read `m.revenue ?? ...`
            // for every row and fell through to the fallback every single
            // time, live. On web that fallback divided the (currency-summed)
            // total by a rank-based number; on mobile it did the same thing
            // with integer division. Same bug in both places: a number that
            // *looks* like one manager's revenue and is actually a fraction
            // of everyone's, picked to make rank 1 look biggest by
            // construction. `clients`/`contracts` in the same row already
            // showed "—" instead of guessing, this is that fix applied to
            // `revenue` too — except here the fix is to actually send the
            // field, not just admit it's missing.
            'manager_stats' => $this->managerStats($isAm, $user, $filters),
        ];

        return response()->json($data);
    }

    /**
     * Per-manager rollup for the AM leaderboard. An account manager only
     * ever sees their own row here — matches how every other query on this
     * endpoint scopes an AM to themselves. A super admin sees every manager
     * they created (or just the one named by `manager_id`, same filter the
     * rest of this report already honours), ranked by revenue descending.
     *
     * Same known limitation as `payments_by_month` above: revenue is summed
     * across currencies with no exchange rate. Not fixed here for the same
     * reason — it needs a shape change with multiple consumers, so it's its
     * own piece of work.
     */
    private function managerStats(bool $isAm, $user, array $filters = []): array
    {
        $managersQuery = \App\Models\User::where('role', \App\Models\User::ROLE_ACCOUNT_MANAGER);
        if ($isAm) {
            $managersQuery->where('id', $user->id);
        } else {
            $managersQuery->where('super_admin_id', $user->id);
            if (!empty($filters['manager_id'])) {
                $managersQuery->where('id', $filters['manager_id']);
            }
        }

        return $managersQuery->get()->map(function ($manager) use ($filters) {
            $clientIds = \App\Models\Client::where('manager_id', $manager->id)->pluck('id');
            $workspaceIds = \App\Models\Workspace::whereIn('client_id', $clientIds)->pluck('id');

            $paymentQuery = \App\Models\Payment::whereIn('client_id', $clientIds)->where('status', 'approved');
            $this->applyDateRange($paymentQuery, $filters);

            $contractQuery = \App\Models\Contract::whereIn('workspace_id', $workspaceIds);
            $this->applyDateRange($contractQuery, $filters);

            return [
                'id' => $manager->id,
                'name' => $manager->name,
                'revenue' => (float) $paymentQuery->sum('amount'),
                'clients' => $clientIds->count(),
                'contracts' => $contractQuery->count(),
            ];
        })
            ->sortByDesc('revenue')
            ->values()
            ->toArray();
    }

    private function applyClientFilters(\Illuminate\Database\Eloquent\Builder $q, array $filters): void
    {
        if (!empty($filters['client_id'])) {
            $q->where('client_id', $filters['client_id']);
        }
    }

    private function applyDateRange(\Illuminate\Database\Eloquent\Builder $q, array $filters, string $column = 'created_at'): void
    {
        if (!empty($filters['date_from'])) {
            $q->whereDate($column, '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $q->whereDate($column, '<=', $filters['date_to']);
        }
    }

    private function clientQuery(bool $isAm, $user, array $filters = []): \Illuminate\Database\Eloquent\Builder
    {
        $q = \App\Models\Client::query();
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
        $this->applyDateRange($q, $filters);
        return $q;
    }

    private function workspaceQuery(bool $isAm, $user, array $filters = []): \Illuminate\Database\Eloquent\Builder
    {
        $q = \App\Models\Workspace::query();
        if ($isAm) $q->whereHas('client', fn($cq) => $cq->where('manager_id', $user->id));
        if (!empty($filters['client_id'])) {
            $q->where('client_id', $filters['client_id']);
        }
        $this->applyDateRange($q, $filters);
        return $q;
    }

    private function contractQuery(bool $isAm, $user, array $filters = []): \Illuminate\Database\Eloquent\Builder
    {
        $q = \App\Models\Contract::query();
        if ($isAm) $q->whereHas('workspace.client', fn($cq) => $cq->where('manager_id', $user->id));
        if (!empty($filters['client_id'])) {
            $q->whereHas('workspace', fn($wq) => $wq->where('client_id', $filters['client_id']));
        }
        $this->applyDateRange($q, $filters);
        return $q;
    }

    private function paymentQuery(bool $isAm, $user, array $filters = []): \Illuminate\Database\Eloquent\Builder
    {
        $q = \App\Models\Payment::query();
        if ($isAm) $q->whereHas('client', fn($cq) => $cq->where('manager_id', $user->id));
        $this->applyClientFilters($q, $filters);
        $this->applyDateRange($q, $filters);
        return $q;
    }

    private function approvalQuery(bool $isAm, $user, array $filters = []): \Illuminate\Database\Eloquent\Builder
    {
        $q = \App\Models\Approval::query();
        if ($isAm) $q->whereHas('workspace.client', fn($cq) => $cq->where('manager_id', $user->id));
        if (!empty($filters['client_id'])) {
            $q->whereHas('workspace', fn($wq) => $wq->where('client_id', $filters['client_id']));
        }
        $this->applyDateRange($q, $filters);
        return $q;
    }

    private function auditLogQuery(bool $isAm, $user, array $filters = []): \Illuminate\Database\Eloquent\Builder
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
        $this->applyDateRange($q, $filters);
        return $q;
    }
}
