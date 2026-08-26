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
            ->when($request->filled('q'), function ($q) use ($request) {
                $search = $request->q;
                $q->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->withCount('managedClients')
            ->latest()
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

    public function destroy(Request $request, User $manager): JsonResponse
    {
        if (!$request->user()->isSuperAdmin()) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        if ($manager->role !== User::ROLE_ACCOUNT_MANAGER) {
            return response()->json(['message' => 'المستخدم ليس مدير حسابات'], 422);
        }

        $manager->delete();

        AuditLog::create([
            'auditable_type' => User::class,
            'auditable_id' => $manager->id,
            'user_id' => $request->user()->id,
            'action' => 'account_manager.deleted',
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['message' => 'تم حذف مدير الحسابات']);
    }
}
