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
 * "My team" — the company staff an account manager picked
 * (PLAN_2_MANAGER_TEAMS.md ق١، ق٢، ق٧). Account managers only: the super admin
 * manages staff elsewhere and assistants/staff/clients never get here.
 *
 * Removing someone from the team also un-assigns them from THIS manager's
 * clients (other managers' clients are untouched), in one transaction with
 * its audit rows.
 */
class ManagerTeamController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $manager = $this->manager($request);

        $workspaces = Workspace::where('manager_id', $manager->id)->with('client')->get()->keyBy('id');

        $team = $manager->managerTeam()
            ->whereNull('users.archived_at')
            ->with('staffManagers:id,name')
            ->orderBy('users.name')->orderBy('users.id')
            ->get();

        $assignments = DB::table('workspace_staff')
            ->whereIn('workspace_id', $workspaces->keys())
            ->whereIn('user_id', $team->pluck('id'))
            ->get()
            ->groupBy('user_id');

        $teamIds = $team->pluck('id')->all();

        $others = User::where('role', User::ROLE_STAFF_MEMBER)
            ->whereNull('archived_at')->where('is_active', true)
            ->whereNotIn('id', $teamIds)
            ->with('staffManagers:id,name')
            ->orderBy('name')->orderBy('id')
            ->get();

        return response()->json([
            'team' => $team->map(fn (User $m) => $this->shape($m, $manager) + [
                'assignments' => ($assignments[$m->id] ?? collect())->map(fn ($row) => $this->assignment($row, $workspaces))->filter()->values(),
            ])->values(),
            'others' => $others->map(fn (User $m) => $this->shape($m, $manager))->values(),
        ]);
    }

    public function add(Request $request, User $member): JsonResponse
    {
        $manager = $this->manager($request);
        abort_unless($member->isStaffMember(), 404);
        abort_unless($member->isActive(), 422, 'الموظف ده متأرشف.');

        $added = DB::transaction(function () use ($manager, $member, $request) {
            $changes = $manager->managerTeam()->syncWithoutDetaching([$member->id => ['added_by' => $manager->id]]);
            $attached = ! empty($changes['attached']);

            if ($attached) {
                $this->audit($request, $member, 'staff.team_added', ['manager_id' => $manager->id]);
            }

            return $attached;
        });

        return response()->json(['staff' => $this->shape($member->load('staffManagers:id,name'), $manager), 'changed' => $added]);
    }

    /** How many (and which) of this manager's clients the member would be taken off if removed. */
    public function removalImpact(Request $request, User $member): JsonResponse
    {
        $manager = $this->manager($request);
        abort_unless($manager->managerTeam()->whereKey($member->id)->exists(), 404);

        $clients = $this->impactedWorkspaces($manager, $member)
            ->map(fn (Workspace $w) => $w->client?->company_name ?: $w->client?->contact_person)
            ->filter()->values();

        return response()->json(['count' => $clients->count(), 'clients' => $clients]);
    }

    public function remove(Request $request, User $member): JsonResponse
    {
        $manager = $this->manager($request);
        abort_unless($manager->managerTeam()->whereKey($member->id)->exists(), 404);

        $unassigned = DB::transaction(function () use ($manager, $member, $request) {
            $workspaces = $this->impactedWorkspaces($manager, $member);

            foreach ($workspaces as $ws) {
                $ws->staff()->detach($member->id);
                AuditLog::create([
                    'auditable_type' => User::class,
                    'auditable_id' => $member->id,
                    'client_id' => $ws->client_id,
                    'user_id' => $manager->id,
                    'action' => 'staff.unassigned',
                    'metadata' => ['workspace_id' => $ws->id, 'staff_name' => $member->name, 'reason' => 'team_removed'],
                    'ip_address' => $request->ip(),
                ]);
            }

            $manager->managerTeam()->detach($member->id);
            $this->audit($request, $member, 'staff.team_removed', [
                'manager_id' => $manager->id,
                'unassigned_count' => $workspaces->count(),
            ]);

            return $workspaces->count();
        });

        return response()->json(['removed' => true, 'unassigned_count' => $unassigned]);
    }

    private function impactedWorkspaces(User $manager, User $member)
    {
        return Workspace::where('manager_id', $manager->id)
            ->whereIn('id', DB::table('workspace_staff')->where('user_id', $member->id)->select('workspace_id'))
            ->with('client')
            ->get();
    }

    private function manager(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->isAccountManager(), 403, 'غير مصرح');

        return $user;
    }

    private function assignment($row, $workspaces): ?array
    {
        $ws = $workspaces[$row->workspace_id] ?? null;
        if (! $ws) {
            return null;
        }

        return [
            'workspace_id' => $ws->id,
            'client_id' => $ws->client_id,
            'uuid' => $ws->client?->uuid,
            'name' => $ws->client?->company_name ?: $ws->client?->contact_person,
            'permissions' => Workspace::decodeStaffPermissions($row->permissions),
        ];
    }

    private function shape(User $member, User $manager): array
    {
        return [
            'id' => $member->id,
            'name' => $member->name,
            'email' => $member->email,
            'avatar_url' => $member->avatar_url,
            // Other managers' teams this member is also in (not mine).
            'other_teams' => $member->staffManagers
                ->where('id', '!=', $manager->id)
                ->map(fn ($m) => ['manager_id' => $m->id, 'name' => $m->name])
                ->values(),
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
            'metadata' => ['staff_name' => $member->name] + $metadata,
            'ip_address' => $request->ip(),
        ]);
    }
}
