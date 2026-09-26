<?php

namespace App\Domains\Dashboard;

use App\Models\Approval;
use App\Models\ChatMessage;
use App\Models\Client;
use App\Models\Contract;
use App\Models\FileEntry;
use App\Models\Payment;
use App\Models\SubUser;
use App\Models\User;
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

    /**
     * GET /dashboard/pending-approvals (plans/pending-approvals-plan.md ك1).
     *
     * The SA/AM home screens' "Pending Approvals" section used to only
     * list approval-request items, hidden entirely once that one
     * sub-count hit zero (ن1), and didn't exist at all on the AM home
     * (ن2) — even though the badge/card right next to it always summed
     * all three item types via DashboardScope::pendingApprovalsTotal().
     * Every query below is built from that SAME set of DashboardScope
     * builders, so this list can never disagree with that count the way
     * it already had, twice (see that method's docblock).
     *
     * Items are split per the plan (ن4): "awaiting_you" is what the
     * staff member themselves must act on (a contract the client already
     * approved, now waiting on company approval; a payment proof waiting
     * for review); "awaiting_client" is blocked on the client instead (a
     * contract sent to them; an approval request raised to them) —
     * surfaced for visibility, not because the manager can act on it
     * directly. `counts` is always the untruncated total (identical to
     * /badge-counts' approvals number and /dashboard/stats'
     * approvals.total) even though each list below is capped.
     */
    public function pendingApprovals(Request $request): JsonResponse
    {
        $user = $request->user();
        $isAm = $user->isAccountManager();
        $filters = $request->only(['manager_id']);

        // Oldest-first, not newest-first: the longest-waiting item is the
        // most urgent one, and capping at "most recent N" (the way
        // /all-contracts does) is exactly bug ن3 — an old 'sent' contract
        // could silently fall off a recency-capped list while still being
        // counted in the total. `limit` is a query param (not yet used by
        // the web UI) so ك5's mobile migration can ask for more than 50
        // without a second endpoint.
        $limit = (int) $request->input('limit', 50);
        if ($limit < 1 || $limit > 200) {
            $limit = 50;
        }

        $awaitingYouContracts = (clone DashboardScope::contracts($isAm, $user, $filters))
            ->where('status', 'client_approved')
            ->with('workspace.client:id,uuid,company_name')
            ->oldest('updated_at')
            ->limit($limit)
            ->get(['id', 'workspace_id', 'title', 'value', 'currency', 'status', 'updated_at']);

        $awaitingYouPayments = (clone DashboardScope::payments($isAm, $user, $filters))
            ->where('status', 'pending')
            ->with('client:id,uuid,company_name')
            ->oldest('created_at')
            ->limit($limit)
            ->get(['id', 'workspace_id', 'client_id', 'amount', 'currency', 'status', 'created_at']);

        $awaitingClientContracts = (clone DashboardScope::contracts($isAm, $user, $filters))
            ->where('status', 'sent')
            ->with('workspace.client:id,uuid,company_name')
            ->oldest('updated_at')
            ->limit($limit)
            ->get(['id', 'workspace_id', 'title', 'value', 'currency', 'status', 'updated_at']);

        $awaitingClientApprovals = (clone DashboardScope::approvals($isAm, $user, $filters))
            ->where('status', 'pending')
            ->with('workspace.client:id,uuid,company_name')
            ->oldest('created_at')
            ->limit($limit)
            ->get(['id', 'workspace_id', 'title', 'status', 'created_at']);

        return response()->json([
            'awaiting_you' => [
                'contracts' => $awaitingYouContracts->map(fn (Contract $c) => $this->mapContractItem($c))->values(),
                'payments' => $awaitingYouPayments->map(fn (Payment $p) => $this->mapPaymentItem($p))->values(),
            ],
            'awaiting_client' => [
                'contracts' => $awaitingClientContracts->map(fn (Contract $c) => $this->mapContractItem($c))->values(),
                'approvals' => $awaitingClientApprovals->map(fn (Approval $a) => $this->mapApprovalItem($a))->values(),
            ],
            'counts' => DashboardScope::pendingApprovalsTotal($isAm, $user, $filters),
        ]);
    }

    /** @return array{id: int, uuid: string, company_name: ?string}|null */
    private function clientSummary(?Client $client): ?array
    {
        if (!$client) {
            return null;
        }

        return ['id' => $client->id, 'uuid' => $client->uuid, 'company_name' => $client->company_name];
    }

    private function mapContractItem(Contract $contract): array
    {
        return [
            'id' => $contract->id,
            'type' => 'contract',
            'title' => $contract->title,
            'value' => $contract->value,
            'currency' => $contract->currency,
            'status' => $contract->status,
            'workspace_id' => $contract->workspace_id,
            'updated_at' => $contract->updated_at,
            'client' => $this->clientSummary($contract->workspace?->client),
        ];
    }

    private function mapPaymentItem(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'type' => 'payment',
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'status' => $payment->status,
            'workspace_id' => $payment->workspace_id,
            'created_at' => $payment->created_at,
            'client' => $this->clientSummary($payment->client),
        ];
    }

    private function mapApprovalItem(Approval $approval): array
    {
        return [
            'id' => $approval->id,
            'type' => 'approval',
            'title' => $approval->title,
            'status' => $approval->status,
            'workspace_id' => $approval->workspace_id,
            'created_at' => $approval->created_at,
            'client' => $this->clientSummary($approval->workspace?->client),
        ];
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

        // plans/notifications-badges-toasts-plan.md ن7 — this used to be
        // `sender_type != Client::class`, which only excludes the primary
        // client's own messages. A sub-user's own sent messages (sender_type
        // = SubUser::class) still matched "!= Client::class", so this
        // counted a sub-user's own outgoing messages as unread — both for
        // the client's badge and for that same sub-user's own badge. The
        // client and its sub-users are treated as one "client side" here
        // (§3 س5); the only real "other side" is staff (User).
        $chat = ChatMessage::where('workspace_id', $wsId)
            ->where('sender_type', User::class)
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

        $payments = Payment::whereIn('status', ['scheduled', 'pending', 'overdue'])
            ->count();

        // 24 Sept 2026 — was three separate counts added up by hand here
        // (pending approval requests + pending contracts + pending
        // payments); now the same DashboardScope::pendingApprovalsTotal()
        // that powers GET /dashboard/stats' approvals card, so the two can
        // never drift apart again the way they already had once (23 Sept
        // 2026 — this badge used to omit pending payments entirely).
        $approvals = DashboardScope::pendingApprovalsTotal(false, $user)['total'];

        $files = FileEntry::where('status', 'pending')
            ->count();

        $notifications = $user->notifications()->whereNull('read_at')->count();

        return response()->json([
            'chat' => $chat,
            'contracts' => 0,
            'approvals' => $approvals,
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

        $payments = Payment::whereIn('workspace_id', $workspaceIds)
            ->whereIn('status', ['scheduled', 'pending', 'overdue'])
            ->count();

        // 24 Sept 2026 — was three separate counts added up by hand here
        // (pending approval requests + pending contracts + pending
        // payments); now the same DashboardScope::pendingApprovalsTotal()
        // that powers GET /dashboard/stats' approvals card, so the two can
        // never drift apart again the way they already had once (23 Sept
        // 2026 — this badge used to omit pending payments entirely). The
        // 'payments' key above is a different, broader count
        // (scheduled/overdue too) that no AM screen displays; left as-is.
        $approvals = DashboardScope::pendingApprovalsTotal(true, $user)['total'];

        $files = FileEntry::whereIn('workspace_id', $workspaceIds)
            ->where('status', 'pending')
            ->count();

        $notifications = $user->notifications()->whereNull('read_at')->count();

        return response()->json([
            'chat' => $chat,
            'contracts' => 0,
            'approvals' => $approvals,
            'payments' => $payments,
            'files' => $files,
            'notifications' => $notifications,
        ]);
    }
}
