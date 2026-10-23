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
 * Company staff members, managed by the super admin only
 * (STAFF_TEAMS_PLAN.md ف٢). Never deleted: archiving keeps the row and the
 * real name so the audit log and the chat still say who did what.
 *
 * Every write runs in one transaction with its audit row — a change that
 * cannot be recorded does not happen (same rule as TeamController).
 */
class StaffController extends Controller
{
    private const ROLE_LABEL = 'موظف بالشركة';

    public function index(Request $request): JsonResponse
    {
        // Read-only listing: the accountant may see the team (ACCOUNTANT_ROLE_PLAN.md).
        $viewer = $request->user();
        abort_unless($viewer instanceof User && $viewer->seesAllFinance(), 403, 'غير مصرح');

        $members = User::where('role', User::ROLE_STAFF_MEMBER)
            ->withCount('assignedWorkspaces')
            ->with('staffManagers:id,name')
            ->when(! $request->boolean('include_archived'), fn ($q) => $q->whereNull('archived_at'))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%' . $request->q . '%';
                $q->where(fn ($w) => $w->where('name', 'like', $term)->orWhere('email', 'like', $term));
            })
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return response()->json(['staff' => $members->map(fn (User $m) => $this->shape($m))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $admin = $this->superAdmin($request);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'unique:users', new UniqueLoginEmail()],
            'password' => 'required|string|min:8|regex:/[A-Za-z]/|regex:/[0-9]/',
            'phone' => 'nullable|string|max:20',
            'date_of_birth' => 'nullable|date',
            'send_email' => 'sometimes|boolean',
        ]);

        $member = DB::transaction(function () use ($data, $admin, $request) {
            $member = User::create([
                'name' => $data['name'],
                'email' => strtolower(trim($data['email'])),
                'phone' => $data['phone'] ?? null,
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'password' => $data['password'],
                'role' => User::ROLE_STAFF_MEMBER,
                'super_admin_id' => $admin->id,
                'is_active' => true,
            ]);

            $this->audit($request, $member, 'staff.created', ['email' => $member->email]);

            return $member;
        });

        if ($request->boolean('send_email', true)) {
            CredentialsMailer::send($member->name, $member->email, $data['password'], self::ROLE_LABEL);
        }

        return response()->json(['staff' => $this->shape($member->fresh())], 201);
    }

    public function update(Request $request, User $member): JsonResponse
    {
        $this->superAdmin($request);
        $this->ensureStaffMember($member);

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => ['sometimes', 'email', 'unique:users,email,' . $member->id, new UniqueLoginEmail('users', $member->id)],
            'phone' => 'nullable|string|max:20',
            'date_of_birth' => 'nullable|date',
        ]);

        DB::transaction(function () use ($data, $member, $request) {
            $fields = array_intersect_key($data, array_flip(['name', 'email', 'phone', 'date_of_birth']));
            if (isset($fields['email'])) {
                $fields['email'] = strtolower(trim($fields['email']));
            }

            $emailChanged = isset($fields['email']) && $fields['email'] !== $member->email;
            $member->update($fields);
            if ($emailChanged) {
                // The login changed: any open session was for the old one.
                $member->tokens()->delete();
            }

            $this->audit($request, $member, 'staff.updated', ['fields' => array_keys($fields)]);
        });

        return response()->json(['staff' => $this->shape($member->fresh())]);
    }

    public function changePassword(Request $request, User $member): JsonResponse
    {
        $this->superAdmin($request);
        $this->ensureStaffMember($member);

        $data = $request->validate([
            'password' => 'required|string|min:8|regex:/[A-Za-z]/|regex:/[0-9]/',
            'send_email' => 'sometimes|boolean',
        ]);

        DB::transaction(function () use ($data, $member, $request) {
            $member->update(['password' => $data['password']]);
            $member->tokens()->delete();

            $this->audit($request, $member, 'staff.password_changed');
        });

        // Off by default: a password change is usually told in person.
        if ($request->boolean('send_email', false)) {
            CredentialsMailer::send($member->name, $member->email, $data['password'], self::ROLE_LABEL);
        }

        return response()->json(['message' => 'تم تغيير الباسورد.']);
    }

    public function archive(Request $request, User $member): JsonResponse
    {
        $this->superAdmin($request);
        $this->ensureStaffMember($member);

        DB::transaction(function () use ($member, $request) {
            $member->update([
                'is_active' => false,
                'deactivated_at' => now(),
                'archived_at' => $member->archived_at ?? now(),
            ]);
            // Cuts every session (mobile included) and stops pushes at once.
            $member->tokens()->delete();
            MobileNotificationToken::where('tokenable_type', $member::class)
                ->where('tokenable_id', $member->getKey())
                ->delete();

            $this->audit($request, $member, 'staff.archived', ['name' => $member->name]);
        });

        return response()->json(['staff' => $this->shape($member->fresh())]);
    }

    public function unarchive(Request $request, User $member): JsonResponse
    {
        $this->superAdmin($request);
        $this->ensureStaffMember($member);

        DB::transaction(function () use ($member, $request) {
            $member->update([
                'is_active' => true,
                'deactivated_at' => null,
                'archived_at' => null,
            ]);

            $this->audit($request, $member, 'staff.unarchived');
        });

        return response()->json(['staff' => $this->shape($member->fresh())]);
    }

    /** 403 for everyone but the super admin (managers, assistants, staff members). */
    private function superAdmin(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->isSuperAdmin(), 403, 'غير مصرح');

        return $user;
    }

    /** 404 for an id that is not a staff member (a manager's id must not work here). */
    private function ensureStaffMember(User $member): void
    {
        abort_unless($member->isStaffMember(), 404);
    }

    private function shape(User $member): array
    {
        $teams = $member->relationLoaded('staffManagers')
            ? $member->staffManagers->map(fn ($m) => ['manager_id' => $m->id, 'name' => $m->name])->values()
            : $member->staffManagers()->get(['users.id', 'users.name'])->map(fn ($m) => ['manager_id' => $m->id, 'name' => $m->name])->values();

        return [
            'teams' => $teams,
            'id' => $member->id,
            'name' => $member->name,
            'email' => $member->email,
            'phone' => $member->phone,
            'date_of_birth' => $member->date_of_birth?->toDateString(),
            'avatar_url' => $member->avatar_url,
            'role' => $member->role,
            'is_active' => (bool) $member->is_active,
            'archived_at' => $member->archived_at,
            'assigned_clients_count' => (int) ($member->assigned_workspaces_count ?? $member->assignedWorkspaces()->count()),
            'created_at' => $member->created_at,
        ];
    }

    private function audit(Request $request, User $member, string $action, array $metadata = []): void
    {
        AuditLog::create([
            'auditable_type' => User::class,
            'auditable_id' => $member->id,
            'client_id' => null,
            'user_id' => $request->user()->id,
            'action' => $action,
            'metadata' => $metadata ?: null,
            'ip_address' => $request->ip(),
        ]);
    }
}
