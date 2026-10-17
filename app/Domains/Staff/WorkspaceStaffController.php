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
 * The "Team" tab inside a client's workspace (STAFF_TEAMS_PLAN.md ف٣): the
 * account manager who owns the workspace — or the super admin — picks which
 * company staff members work on this client.
 *
 * Staff are company-wide, not a manager's property, so any active staff
 * member can be assigned to any client, and one staff member can be on any
 * number of clients. Un-assigning cuts their access on the next request.
 * Not for assistants, clients, sub-users or staff themselves.
 */
class WorkspaceStaffController extends Controller
{
    /** Every active staff member, each flagged with whether they are on this client. */
    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorizeManage($request, $workspace);

        $assigned = $workspace->staff()->pluck('users.id')->all();

        $staff = User::where('role', User::ROLE_STAFF_MEMBER)
            ->whereNull('archived_at')
            ->where('is_active', true)
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (User $m) => $this->shape($m, in_array($m->id, $assigned, true)))
            ->values();

        return response()->json(['staff' => $staff]);
    }

    public function assign(Request $request, Workspace $workspace, User $member): JsonResponse
    {
        $actor = $this->authorizeManage($request, $workspace);
        abort_unless($member->isStaffMember(), 404);
        abort_unless($member->isActive(), 422, 'الموظف ده متأرشف.');

        $newly = DB::transaction(function () use ($workspace, $member, $actor, $request) {
            $changes = $workspace->staff()->syncWithoutDetaching([
                $member->id => ['assigned_by' => $actor->id],
            ]);
            $attached = ! empty($changes['attached']);

            if ($attached) {
                $this->audit($request, $workspace, $member, 'staff.assigned');
            }

            return $attached;
        });

        if ($newly) {
            \App\Support\StaffNotifier::wasAssigned($workspace, $member);
        }

        return response()->json(['staff' => $this->shape($member, true), 'changed' => $newly]);
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

    private function shape(User $member, bool $assigned): array
    {
        return [
            'id' => $member->id,
            'name' => $member->name,
            'email' => $member->email,
            'avatar_url' => $member->avatar_url,
            'assigned' => $assigned,
        ];
    }

    private function audit(Request $request, Workspace $workspace, User $member, string $action): void
    {
        AuditLog::create([
            'auditable_type' => User::class,
            'auditable_id' => $member->id,
            'client_id' => $workspace->client_id,
            'user_id' => $request->user()->id,
            'action' => $action,
            'metadata' => ['workspace_id' => $workspace->id, 'staff_name' => $member->name],
            'ip_address' => $request->ip(),
        ]);
    }
}
