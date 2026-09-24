<?php

namespace App\Domains\Dashboard;

use App\Models\Approval;
use App\Models\ChatMessage;
use App\Models\Client;
use App\Models\Contract;
use App\Models\FileEntry;
use App\Models\Payment;
use App\Models\SubUser;
use App\Models\Workspace;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class DashboardController extends Controller
{
    /**
     * The server-computed replacement for the dashboard cards that used to
     * be computed client-side from a paginated list (see
     * server-side-stats-plan.md). An account manager only ever gets their
     * own numbers; a super admin gets everyone's, optionally narrowed to one
     * manager the same way /reports already supports.
     *
     * Every number here is a full COUNT/SUM over the whole table, not a
     * count of whatever page happened to load — that was the actual bug
     * this endpoint exists to fix (web and mobile each capped at ~30-100
     * rows and treated that as the total).
     */
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        $isAm = $user->isAccountManager();
        $filters = $request->only(['manager_id']);

        $totalClients = (clone DashboardScope::clients($isAm, $user, $filters))
            ->where('status', '!=', 'archived')
            ->count();

        $activeContracts = (clone DashboardScope::contracts($isAm, $user, $filters))
            ->whereIn('status', ['company_approved', 'completed'])
            ->count();

        $awaitingClientContracts = (clone DashboardScope::contracts($isAm, $user, $filters))
            ->whereIn('status', ['sent', 'client_approved'])
            ->count();

        $pendingPayments = (clone DashboardScope::payments($isAm, $user, $filters))
            ->where('status', 'pending')
            ->count();

        $approvals = DashboardScope::pendingApprovalsTotal($isAm, $user, $filters);

        // "This month" is Egypt wall-clock time, not the app's UTC storage
        // timezone or the browser's local time — the bug this replaces
        // (SAManagersView.tsx summing payments client-side) got the month
        // boundary wrong for exactly this reason.
        $displayTz = config('app.display_timezone', 'Africa/Cairo');
        $nowInTz = Carbon::now($displayTz);
        $monthStartUtc = $nowInTz->copy()->startOfMonth()->setTimezone('UTC');
        $monthEndUtc = $nowInTz->copy()->endOfMonth()->setTimezone('UTC');

        $revenueThisMonth = (clone DashboardScope::payments($isAm, $user, $filters))
            ->where('status', 'approved')
            ->whereBetween('created_at', [$monthStartUtc, $monthEndUtc])
            ->selectRaw('currency, SUM(amount) as total')
            ->groupBy('currency')
            ->pluck('total', 'currency')
            ->map(fn ($v) => (float) $v)
            ->toArray();

        return response()->json([
            'clients' => ['total' => $totalClients],
            'contracts' => ['active' => $activeContracts, 'awaiting_client' => $awaitingClientContracts],
            'payments' => ['pending' => $pendingPayments],
            'approvals' => $approvals,
            'revenue_this_month' => $revenueThisMonth,
            'period' => ['month' => $nowInTz->format('Y-m'), 'timezone' => $displayTz],
        ]);
    }

    public function badgeCounts(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user instanceof Client) {
            return $this->clientCounts($user);
        }

        if ($user instanceof SubUser) {
            return $this->clientCounts($user->client, $user);
        }

        $role = $user->role ?? '';

        if ($role === 'super_admin') {
            return $this->saCounts($user);
        }

        if ($role === 'account_manager') {
            return $this->amCounts($user);
        }

        return response()->json([
            'chat' => 0,
            'contracts' => 0,
            'approvals' => 0,
            'payments' => 0,
            'files' => 0,
            'notifications' => 0,
        ]);
    }

    /**
     * $actingAs is null when the client itself is asking (sees everything it
     * has access to). When a SubUser is asking on behalf of its parent
     * client, each count is zeroed unless the sub-user holds the matching
     * can_view_* permission — otherwise a sub-user locked out of, say, the
     * payments tab would still see a live count of pending payments via the
     * badge, leaking through the side the UI never renders (SUBUSER_PLAN.md
     * §5.1).
     */
    private function clientCounts(Client $client, ?SubUser $actingAs = null): JsonResponse
    {
        $ws = $client->workspace;
        if (!$ws) {
            return response()->json([
                'chat' => 0,
                'contracts' => 0,
                'approvals' => 0,
                'payments' => 0,
                'files' => 0,
                'notifications' => 0,
            ]);
        }

        $wsId = $ws->id;

        $chat = ChatMessage::where('workspace_id', $wsId)
            ->where('sender_type', '!=', Client::class)
            ->whereNull('read_at')
            ->count();

        $contracts = Contract::where('workspace_id', $wsId)
            ->whereIn('status', ['sent', 'client_approved'])
            ->count();

        $approvals = Approval::where('workspace_id', $wsId)
            ->where('status', 'pending')
            ->count();

        $payments = Payment::where('workspace_id', $wsId)
            ->whereIn('status', ['scheduled', 'pending', 'overdue'])
            ->count();

        $files = FileEntry::where('workspace_id', $wsId)
            ->where('status', 'pending')
            ->count();

        if ($actingAs instanceof SubUser) {
            $chat = $actingAs->hasPermission('can_chat') ? $chat : 0;
            $contracts = $actingAs->hasPermission('can_view_contracts') ? $contracts : 0;
            $approvals = $actingAs->hasPermission('can_view_approvals') ? $approvals : 0;
            $payments = $actingAs->hasPermission('can_view_payments') ? $payments : 0;
            $files = $actingAs->hasPermission('can_view_files') ? $files : 0;
        }

        return response()->json([
            'chat' => $chat,
            'contracts' => $contracts,
            'approvals' => $approvals,
            'payments' => $payments,
            'files' => $files,
            'notifications' => 0,
        ]);
    }

    private function saCounts($user): JsonResponse
    {
        $chat = ChatMessage::where('sender_type', '!=', get_class($user))
            ->whereNull('read_at')
            ->count();

        $pendingContracts = Contract::whereIn('status', ['sent', 'client_approved'])
            ->count();

        $pendingApprovals = Approval::where('status', 'pending')
            ->count();

        $payments = Payment::whereIn('status', ['scheduled', 'pending', 'overdue'])
            ->count();

        // See the comment in amCounts() — same reasoning, company-wide.
        $pendingPaymentApprovals = Payment::where('status', 'pending')
            ->count();

        $files = FileEntry::where('status', 'pending')
            ->count();

        $notifications = $user->notifications()->whereNull('read_at')->count();

        return response()->json([
            'chat' => $chat,
            'contracts' => 0,
            'approvals' => $pendingApprovals + $pendingContracts + $pendingPaymentApprovals,
            'payments' => $payments,
            'files' => $files,
            'notifications' => $notifications,
        ]);
    }

    private function amCounts($user): JsonResponse
    {
        $workspaceIds = Workspace::where('manager_id', $user->id)->pluck('id');

        $chat = ChatMessage::whereIn('workspace_id', $workspaceIds)
            ->where('sender_type', '!=', get_class($user))
            ->whereNull('read_at')
            ->count();

        $pendingContracts = Contract::whereIn('workspace_id', $workspaceIds)
            ->whereIn('status', ['sent', 'client_approved'])
            ->count();

        $pendingApprovals = Approval::whereIn('workspace_id', $workspaceIds)
            ->where('status', 'pending')
            ->count();

        $payments = Payment::whereIn('workspace_id', $workspaceIds)
            ->whereIn('status', ['scheduled', 'pending', 'overdue'])
            ->count();

        // 23 Sept 2026 — the mobile Approvals screen (sa_approvals_page.dart,
        // the one this badge opens) lists pending contracts, pending
        // approval requests AND payments awaiting the manager's approval
        // (status 'pending', same filter as GET /payments/pending), but this
        // badge only counted the first two — so with a payment waiting, the
        // list showed more than the badge. The 'payments' key below is a
        // different, broader count (scheduled/overdue too) that no AM screen
        // displays; it's left as-is.
        $pendingPaymentApprovals = Payment::whereIn('workspace_id', $workspaceIds)
            ->where('status', 'pending')
            ->count();

        $files = FileEntry::whereIn('workspace_id', $workspaceIds)
            ->where('status', 'pending')
            ->count();

        $notifications = $user->notifications()->whereNull('read_at')->count();

        return response()->json([
            'chat' => $chat,
            'contracts' => 0,
            'approvals' => $pendingApprovals + $pendingContracts + $pendingPaymentApprovals,
            'payments' => $payments,
            'files' => $files,
            'notifications' => $notifications,
        ]);
    }
}
