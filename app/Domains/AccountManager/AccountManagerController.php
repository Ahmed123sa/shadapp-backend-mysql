<?php

namespace App\Domains\AccountManager;

use App\Models\Client;
use App\Models\User;
use App\Models\AuditLog;
use App\Http\Requests\StoreManagerRequest;
use App\Http\Requests\UpdateManagerRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AccountManagerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user->isSuperAdmin()) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        $managers = User::where('role', User::ROLE_ACCOUNT_MANAGER)
            ->where('super_admin_id', $user->id)
            // Deactivated managers are hidden from the default list — this is
            // where every "who can this be assigned to" screen gets its data
            // from, so a deactivated manager silently disappearing from here
            // is what makes deactivation actually mean something. Pass
            // include_inactive=1 to see them too (e.g. an admin screen that
            // needs to show/reactivate deactivated accounts).
            ->when(!$request->boolean('include_inactive'), function ($q) {
                $q->active();
            })
            ->when($request->filled('q'), function ($q) use ($request) {
                $search = $request->q;
                $q->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->withCount('managedClients')
            // Oldest-created manager first. Was ->latest() (created_at desc),
            // but managers created within the same second (as in tests, and
            // possible in bulk-import scenarios) tie on created_at, and MySQL
            // vs Postgres break that tie differently — the list order wasn't
            // actually deterministic. ->orderBy('id') as a tiebreaker makes
            // it deterministic regardless of database.
            ->oldest()
            ->orderBy('id')
            ->get();

        return response()->json(['managers' => $managers]);
    }

    public function stats(Request $request, User $manager): JsonResponse
    {
        if (!$request->user()->isSuperAdmin()) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        if ($manager->role !== User::ROLE_ACCOUNT_MANAGER) {
            return response()->json(['message' => 'المستخدم ليس مدير حسابات'], 422);
        }

        $clientIds = Client::where('manager_id', $manager->id)->pluck('id');
        $workspaceIds = \App\Models\Workspace::whereIn('client_id', $clientIds)->pluck('id');

        $clientsCount = $clientIds->count();
        $activeWorkspaces = \App\Models\Workspace::whereIn('client_id', $clientIds)->where('status', 'active')->count();

        $contractsByStatus = \App\Models\Contract::whereIn('workspace_id', $workspaceIds)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $totalRevenue = \App\Models\Payment::whereIn('client_id', $clientIds)
            ->where('status', 'approved')
            ->sum('amount');

        $pendingPayments = \App\Models\Payment::whereIn('client_id', $clientIds)
            ->where('status', 'pending')
            ->count();

        $monthExpr = \App\Support\DbExpr::yearMonth('created_at');

        $paymentsByMonth = \App\Models\Payment::whereIn('client_id', $clientIds)
            ->where('status', 'approved')
            ->selectRaw("{$monthExpr} as month, SUM(amount) as total")
            ->groupBy('month')
            ->pluck('total', 'month')
            ->toArray();

        return response()->json([
            'clients_count' => $clientsCount,
            'active_workspaces' => $activeWorkspaces,
            'total_revenue' => $totalRevenue,
            'pending_payments' => $pendingPayments,
            'contracts_by_status' => $contractsByStatus,
            'payments_by_month' => $paymentsByMonth,
        ]);
    }

    public function show(Request $request, User $manager): JsonResponse
    {
        if (!$request->user()->isSuperAdmin()) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        if ($manager->role !== User::ROLE_ACCOUNT_MANAGER) {
            return response()->json(['message' => 'المستخدم ليس مدير حسابات'], 422);
        }

        $clients = Client::with('workspace')
            ->where('manager_id', $manager->id)
            ->latest()
            ->get();

        return response()->json(['manager' => $manager, 'clients' => $clients]);
    }

    public function store(StoreManagerRequest $request): JsonResponse
    {
        if (!$request->user()->isSuperAdmin()) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        $password = $request->password ?? Str::random(12);

        $manager = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'date_of_birth' => $request->date_of_birth,
            'password' => $password,
            'role' => User::ROLE_ACCOUNT_MANAGER,
            'super_admin_id' => $request->user()->id,
        ]);

        AuditLog::create([
            'auditable_type' => User::class,
            'auditable_id' => $manager->id,
            'user_id' => $request->user()->id,
            'action' => 'account_manager.created',
            'metadata' => ['email' => $manager->email, 'phone' => $manager->phone],
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'manager' => $manager,
            'credentials' => [
                'email' => $manager->email,
                'password' => $password,
            ],
        ], 201);
    }

    public function update(UpdateManagerRequest $request, User $manager): JsonResponse
    {
        if (!$request->user()->isSuperAdmin()) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        if ($manager->role !== User::ROLE_ACCOUNT_MANAGER) {
            return response()->json(['message' => 'المستخدم ليس مدير حسابات'], 422);
        }

        $data = $request->only(['name', 'email', 'phone', 'date_of_birth']);
        if ($request->filled('password')) {
            $data['password'] = $request->password;
        }
        $manager->update($data);

        AuditLog::create([
            'auditable_type' => User::class,
            'auditable_id' => $manager->id,
            'user_id' => $request->user()->id,
            'action' => 'account_manager.updated',
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['manager' => $manager->fresh()]);
    }

    // Deliberately no destroy(). Deleting a manager used to cascade-delete
    // every one of their clients (clients.manager_id was
    // ->cascadeOnDelete()) — and every contract, payment, signature and
    // chat message under each of those clients' workspaces along with them.
    // A single delete of one account could take down a company's entire
    // client history with no way back except restoring the whole database.
    // The route is gone too (see routes/api.php) — this comment is the only
    // remaining trace, on purpose, so nobody re-adds it without reading
    // this first. deactivate()/activate() below replace it.

    /**
     * Deactivates an account manager instead of deleting them: blocks their
     * login, kills every existing session (mobile included), and hides them
     * from assignment lists — without touching a single client, contract or
     * payment they're associated with. See DATA_SAFETY_PLAN.md §2.2.
     */
    public function deactivate(Request $request, User $manager): JsonResponse
    {
        if (!$request->user()->isSuperAdmin()) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        if ($manager->role !== User::ROLE_ACCOUNT_MANAGER) {
            return response()->json(['message' => 'المستخدم ليس مدير حسابات'], 422);
        }

        // The whole point of transfer (Part 1) existing is that this path is
        // always available before deactivating someone — a manager should
        // never be left holding clients nobody can see in an active list.
        $clientCount = $manager->managedClients()->count();
        if ($clientCount > 0) {
            return response()->json([
                'message' => "المدير ده لسه مسؤول عن {$clientCount} عميل. انقلهم لمدير تاني الأول.",
            ], 422);
        }

        $manager->update([
            'is_active' => false,
            'deactivated_at' => now(),
        ]);

        // Changing the email password alone does not revoke existing Sanctum
        // tokens — this is the step that actually logs the manager out of
        // every device, mobile included, immediately.
        $manager->tokens()->delete();

        AuditLog::create([
            'auditable_type' => User::class,
            'auditable_id' => $manager->id,
            'user_id' => $request->user()->id,
            'action' => 'account_manager.deactivated',
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['manager' => $manager->fresh()]);
    }

    public function activate(Request $request, User $manager): JsonResponse
    {
        if (!$request->user()->isSuperAdmin()) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        if ($manager->role !== User::ROLE_ACCOUNT_MANAGER) {
            return response()->json(['message' => 'المستخدم ليس مدير حسابات'], 422);
        }

        $manager->update([
            'is_active' => true,
            'deactivated_at' => null,
        ]);

        AuditLog::create([
            'auditable_type' => User::class,
            'auditable_id' => $manager->id,
            'user_id' => $request->user()->id,
            'action' => 'account_manager.activated',
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['manager' => $manager->fresh()]);
    }
}
