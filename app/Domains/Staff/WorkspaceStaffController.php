<?php

namespace App\Domains\Staff;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The "Team" tab inside a client's workspace (STAFF_TEAMS_PLAN.md ف٣ +
 * PLAN_2_MANAGER_TEAMS.md): the account manager who owns the workspace — or
 * the super admin — picks which company staff members work on this client
 * and what they may do there.
 *
 * The super admin can assign any active staff member. A manager assigns from
 * his own team only (ق٧); an assignment that survived a client transfer
 * (ق٤) is still listed, flagged `in_my_team: false`. Un-assigning cuts
 * access on the next request. Not for assistants, clients, sub-users or staff.
 */
class WorkspaceStaffController extends Controller
{
    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $actor = $this->authorizeManage($request, $workspace);

        $assigned = [];
        foreach ($workspace->staff()->get() as $row) {
            $assigned[$row->id] = Workspace::decodeStaffPermissions($row->pivot->permissions);
        }

        $query = User::where('role', User::ROLE_STAFF_MEMBER)
            ->with('staffManagers:id,name')
            ->orderBy('name')
            ->orderBy('id');

        if ($actor->isSuperAdmin()) {
            $members = $query->whereNull('archived_at')->where('is_active', true)->get();
            $teamIds = null;
        } else {
            $teamIds = $actor->managerTeam()->pluck('users.id')->all();
            $members = $query
                ->whereNull('archived_at')->where('is_active', true)
                ->where(fn ($q) => $q->whereIn('id', $teamIds)->orWhereIn('id', array_keys($assigned)))
                ->get();
        }

        $staff = $members->map(fn (User $m) => $this->shape(
            $m,
            isset($assigned[$m->id]),
            $assigned[$m->id] ?? null,
            $teamIds === null ? null : in_array($m->id, $teamIds, true),
        ))->values();

        return response()->json(['staff' => $staff]);
    }

    public function assign(Request $request, Workspace $workspace, User $member): JsonResponse
    {
        $actor = $this->authorizeManage($request, $workspace);
        abort_unless($member->isStaffMember(), 404);
        abort_unless($member->isActive(), 422, 'الموظف ده متأرشف.');
        $this->requireInTeam($actor, $member);

        $data = $this->validatePermissions($request);

        [$newly, $permChanged, $map] = DB::transaction(function () use ($workspace, $member, $actor, $request, $data) {
            $existing = $workspace->staff()->whereKey($member->id)->first();
            $current = Workspace::decodeStaffPermissions($existing?->pivot?->permissions);

            // New assignment: given map over defaults (all on). Existing one:
            // only change permissions when the caller actually sent some.
            $hasPerms = array_key_exists('permissions', $data) && is_array($data['permissions']);
            $map = $hasPerms ? User::staffPermissionMap($data['permissions'], $existing ? $current : null) : $current;

            $changes = $workspace->staff()->syncWithoutDetaching([
                $member->id => [
                    'assigned_by' => $existing ? $existing->pivot->assigned_by : $actor->id,
                ],
            ]);
            $attached = ! empty($changes['attached']);

            $permChanged = false;
            if ($attached || ($hasPerms && $map !== $current)) {
                $workspace->staff()->updateExistingPivot($member->id, [
                    'permissions' => $this->encode($map),
                ]);
                $permChanged = ! $attached;
            }

            if ($attached) {
                $this->audit($request, $workspace, $member, 'staff.assigned');
            }
            if ($permChanged) {
                $this->audit($request, $workspace, $member, 'staff.permissions_updated', ['permissions' => $map]);
            }

            return [$attached, $permChanged, $map];
        });

        if ($newly) {
            \App\Support\StaffNotifier::wasAssigned($workspace, $member);
        }

        return response()->json([
            'staff' => $this->shape($member, true, $map, $actor->isSuperAdmin() ? null : true),
            'changed' => $newly,
            'permissions_changed' => $permChanged,
        ]);
    }

    /** Change the permissions of an existing assignment. */
    public function updatePermissions(Request $request, Workspace $workspace, User $member): JsonResponse
    {
        $actor = $this->authorizeManage($request, $workspace);
        abort_unless($member->isStaffMember(), 404);
        $this->requireInTeam($actor, $member);

        $data = $request->validate($this->permissionRules(true));

        $map = DB::transaction(function () use ($workspace, $member, $request, $data) {
            $existing = $workspace->staff()->whereKey($member->id)->first();
            abort_unless($existing, 404, 'الموظف ده مش معيّن على العميل.');

            $current = Workspace::decodeStaffPermissions($existing->pivot->permissions);
            $map = User::staffPermissionMap($data['permissions'], $current);

            if ($map !== $current) {
                $workspace->staff()->updateExistingPivot($member->id, [
                    'permissions' => $this->encode($map),
                ]);
                $this->audit($request, $workspace, $member, 'staff.permissions_updated', ['permissions' => $map]);
            }

            return $map;
        });

        return response()->json(['staff' => $this->shape($member, true, $map, $actor->isSuperAdmin() ? null : true)]);
    }

    public function unassign(Request $request, Workspace $workspace, User $member): JsonResponse
    {
        $this->authorizeManage($request, $workspace);
        abort_unless($member->isStaffMember(), 404);

        $removed = DB::transaction(function () use ($workspace, $member, $request) {
            $detached = $workspace->staff()->detach($member->id) > 0;

            if ($detached) {
                $this->audit($request, $workspace, $member, 'staff.unassigned');
            }

            return $detached;
        });

        return response()->json(['staff' => $this->shape($member, false), 'changed' => $removed]);
    }

    /** The clients one staff member is on — the super admin's view from the Teams tab. */
    public function clientsOf(Request $request, User $member): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->isSuperAdmin(), 403, 'غير مصرح');
        abort_unless($member->isStaffMember(), 404);

        $workspaces = $member->assignedWorkspaces()->with('client', 'manager:id,name')->get();

        return response()->json(['clients' => $workspaces->map(fn (Workspace $w) => [
            'workspace_id' => $w->id,
            'client_id' => $w->client_id,
            'uuid' => $w->client?->uuid,
            'name' => $w->client?->company_name ?: $w->client?->contact_person,
            'manager' => $w->manager ? ['id' => $w->manager->id, 'name' => $w->manager->name] : null,
        ])->values()]);
    }

    /** Super admin, or this workspace's own account manager — nobody else (no assistants, staff or clients). */
    private function authorizeManage(Request $request, Workspace $workspace): User
    {
        $user = $request->user();
        abort_unless(
            $user instanceof User
                && ($user->isSuperAdmin() || ($user->isAccountManager() && $workspace->manager_id === $user->id)),
            403,
            'غير مصرح'
        );

        return $user;
    }

    /** A manager may only put people from his own team on a client (ق٧); the super admin is exempt. */
    private function requireInTeam(User $actor, User $member): void
    {
        if ($actor->isSuperAdmin()) {
            return;
        }

        abort_unless(
            $actor->managerTeam()->whereKey($member->id)->exists(),
            422,
            'الموظف ده مش في فريقك. ضيفه لفريقك الأول.'
        );
    }

    private function encode(array $map): ?string
    {
        $stored = User::staffPermissionsForStorage($map);

        return $stored === null ? null : json_encode($stored);
    }

    private function permissionRules(bool $required = false): array
    {
        $rules = ['permissions' => [$required ? 'required' : 'nullable', 'array']];
        foreach (User::STAFF_PERMISSION_KEYS as $key) {
            $rules["permissions.$key"] = 'sometimes|boolean';
        }

        return $rules;
    }

    private function validatePermissions(Request $request): array
    {
        return $request->validate($this->permissionRules());
    }

    private function shape(User $member, bool $assigned, ?array $permissions = null, ?bool $inMyTeam = null): array
    {
        $shape = [
            'id' => $member->id,
            'name' => $member->name,
            'email' => $member->email,
            'avatar_url' => $member->avatar_url,
            'assigned' => $assigned,
            'permissions' => $assigned ? ($permissions ?? User::staffPermissionMap(null)) : null,
        ];

        if ($inMyTeam !== null) {
            $shape['in_my_team'] = $inMyTeam;
        }
        if ($member->relationLoaded('staffManagers')) {
            $shape['teams'] = $member->staffManagers->map(fn ($m) => ['manager_id' => $m->id, 'name' => $m->name])->values();
        }

        return $shape;
    }

    private function audit(Request $request, Workspace $workspace, User $member, string $action, array $extra = []): void
    {
        AuditLog::create([
            'auditable_type' => User::class,
            'auditable_id' => $member->id,
            'client_id' => $workspace->client_id,
            'user_id' => $request->user()->id,
            'action' => $action,
            'metadata' => ['workspace_id' => $workspace->id, 'staff_name' => $member->name] + $extra,
            'ip_address' => $request->ip(),
        ]);
    }
}
