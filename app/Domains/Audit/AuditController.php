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
            'payments_by_month' => (clone $this->paymentQuery($isAm, $user, $filters))
                ->selectRaw(\App\Support\DbExpr::yearMonth('created_at') . ' as month, SUM(amount) as total')
                ->groupBy('month')
                ->pluck('total', 'month')
                ->toArray(),
            'approval_stats' => [
                'approved' => (clone $this->approvalQuery($isAm, $user, $filters))->where('status', 'approved')->count(),
                'rejected' => (clone $this->approvalQuery($isAm, $user, $filters))->where('status', 'rejected')->count(),
                'pending' => (clone $this->approvalQuery($isAm, $user, $filters))->where('status', 'pending')->count(),
            ],
        ];

        return response()->json($data);
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
