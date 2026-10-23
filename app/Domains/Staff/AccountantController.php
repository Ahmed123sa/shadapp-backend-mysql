<?php

namespace App\Domains\Staff;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\MobileNotificationToken;
use App\Models\User;
use App\Rules\UniqueLoginEmail;
use App\Support\CredentialsMailer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Accountant accounts (ACCOUNTANT_ROLE_PLAN.md ٤.٨), managed by the super
 * admin only. An accountant is never deleted: deactivating keeps the row and
 * the real name, so the audit log still says who approved or rejected what.
 *
 * Every write runs in one transaction with its audit row — a change that
 * cannot be recorded does not happen (same rule as StaffController).
 */
class AccountantController extends Controller
{
    private const ROLE_LABEL = 'محاسب';

    public function index(Request $request): JsonResponse
    {
        $this->superAdmin($request);

        $accountants = User::where('role', User::ROLE_ACCOUNTANT)
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%' . $request->q . '%';
                $q->where(fn ($w) => $w->where('name', 'like', $term)->orWhere('email', 'like', $term));
            })
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return response()->json(['accountants' => $accountants->map(fn (User $a) => $this->shape($a))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $admin = $this->superAdmin($request);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'unique:users', new UniqueLoginEmail()],
            'password' => 'required|string|min:8|regex:/[A-Za-z]/|regex:/[0-9]/',
            'phone' => 'nullable|string|max:20',
            'send_email' => 'sometimes|boolean',
        ]);

        $accountant = DB::transaction(function () use ($data, $admin, $request) {
            $accountant = User::create([
                'name' => $data['name'],
                'email' => strtolower(trim($data['email'])),
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
                'role' => User::ROLE_ACCOUNTANT,
                'super_admin_id' => $admin->id,
                'is_active' => true,
            ]);

            $this->audit($request, $accountant, 'accountant.created', ['email' => $accountant->email]);

            return $accountant;
        });

        if ($request->boolean('send_email', true)) {
            CredentialsMailer::send($accountant->name, $accountant->email, $data['password'], self::ROLE_LABEL);
        }

        return response()->json(['accountant' => $this->shape($accountant->fresh())], 201);
    }

    public function update(Request $request, User $accountant): JsonResponse
    {
        $this->superAdmin($request);
        $this->ensureAccountant($accountant);

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => ['sometimes', 'email', 'unique:users,email,' . $accountant->id, new UniqueLoginEmail('users', $accountant->id)],
            'phone' => 'nullable|string|max:20',
        ]);

        DB::transaction(function () use ($data, $accountant, $request) {
            $fields = array_intersect_key($data, array_flip(['name', 'email', 'phone']));
            if (isset($fields['email'])) {
                $fields['email'] = strtolower(trim($fields['email']));
            }

            $emailChanged = isset($fields['email']) && $fields['email'] !== $accountant->email;
            $accountant->update($fields);
            if ($emailChanged) {
                // The login changed: any open session was for the old one.
                $accountant->tokens()->delete();
            }

            $this->audit($request, $accountant, 'accountant.updated', ['fields' => array_keys($fields)]);
        });

        return response()->json(['accountant' => $this->shape($accountant->fresh())]);
    }

    public function changePassword(Request $request, User $accountant): JsonResponse
    {
        $this->superAdmin($request);
        $this->ensureAccountant($accountant);

        $data = $request->validate([
            'password' => 'required|string|min:8|regex:/[A-Za-z]/|regex:/[0-9]/',
            'send_email' => 'sometimes|boolean',
        ]);

        DB::transaction(function () use ($data, $accountant, $request) {
            $accountant->update(['password' => $data['password']]);
            $accountant->tokens()->delete();

            $this->audit($request, $accountant, 'accountant.password_changed');
        });

        if ($request->boolean('send_email', false)) {
            CredentialsMailer::send($accountant->name, $accountant->email, $data['password'], self::ROLE_LABEL);
        }

        return response()->json(['message' => 'تم تغيير الباسورد.']);
    }

    public function deactivate(Request $request, User $accountant): JsonResponse
    {
        $this->superAdmin($request);
        $this->ensureAccountant($accountant);

        DB::transaction(function () use ($accountant, $request) {
            $accountant->update(['is_active' => false, 'deactivated_at' => now()]);
            // Cuts every session (mobile included) and stops pushes at once.
            $accountant->tokens()->delete();
            MobileNotificationToken::where('tokenable_type', $accountant::class)
                ->where('tokenable_id', $accountant->getKey())
                ->delete();

            $this->audit($request, $accountant, 'accountant.deactivated', ['name' => $accountant->name]);
        });

        return response()->json(['accountant' => $this->shape($accountant->fresh())]);
    }

    public function activate(Request $request, User $accountant): JsonResponse
    {
        $this->superAdmin($request);
        $this->ensureAccountant($accountant);

        DB::transaction(function () use ($accountant, $request) {
            $accountant->update(['is_active' => true, 'deactivated_at' => null]);

            $this->audit($request, $accountant, 'accountant.activated');
        });

        return response()->json(['accountant' => $this->shape($accountant->fresh())]);
    }

    /** 403 for everyone but the super admin — the accountant cannot manage accountants. */
    private function superAdmin(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->isSuperAdmin(), 403, 'غير مصرح');

        return $user;
    }

    /** 404 for an id that is not an accountant (a manager's id must not work here). */
    private function ensureAccountant(User $accountant): void
    {
        abort_unless($accountant->isAccountant(), 404);
    }

    private function shape(User $accountant): array
    {
        return [
            'id' => $accountant->id,
            'name' => $accountant->name,
            'email' => $accountant->email,
            'phone' => $accountant->phone,
            'avatar_url' => $accountant->avatar_url,
            'role' => $accountant->role,
            'is_active' => (bool) $accountant->is_active,
            'deactivated_at' => $accountant->deactivated_at,
            'created_at' => $accountant->created_at,
        ];
    }

    private function audit(Request $request, User $accountant, string $action, array $metadata = []): void
    {
        AuditLog::create([
            'auditable_type' => User::class,
            'auditable_id' => $accountant->id,
            'client_id' => null,
            'user_id' => $request->user()->id,
            'action' => $action,
            'metadata' => $metadata ?: null,
            'ip_address' => $request->ip(),
        ]);
    }
}
