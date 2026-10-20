<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * PLAN_2_MANAGER_TEAMS.md ف١ — "my team" endpoints.
 */
class ManagerTeamTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private User $taha;
    private User $mariam;
    private User $huda;
    private User $omar;
    private Client $c1;
    private Client $c2;
    private Client $cm;
    private Workspace $w1;
    private Workspace $w2;
    private Workspace $wm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $this->taha = $this->manager('Taha');
        $this->mariam = $this->manager('Mariam');
        $this->huda = $this->member('Huda');
        $this->omar = $this->member('Omar');

        $this->c1 = Client::factory()->create(['manager_id' => $this->taha->id, 'company_name' => 'Nile Bakery']);
        $this->c2 = Client::factory()->create(['manager_id' => $this->taha->id, 'company_name' => 'Delta Foods']);
        $this->cm = Client::factory()->create(['manager_id' => $this->mariam->id, 'company_name' => 'Sun Co']);
        $this->w1 = Workspace::factory()->create(['client_id' => $this->c1->id, 'manager_id' => $this->taha->id, 'status' => 'active']);
        $this->w2 = Workspace::factory()->create(['client_id' => $this->c2->id, 'manager_id' => $this->taha->id, 'status' => 'active']);
        $this->wm = Workspace::factory()->create(['client_id' => $this->cm->id, 'manager_id' => $this->mariam->id, 'status' => 'active']);
    }

    private function manager(string $name): User
    {
        return User::factory()->create(['name' => $name, 'role' => User::ROLE_ACCOUNT_MANAGER, 'super_admin_id' => $this->superAdmin->id, 'is_active' => true]);
    }

    private function member(string $name): User
    {
        return User::factory()->create(['name' => $name, 'role' => User::ROLE_STAFF_MEMBER, 'is_active' => true, 'password' => 'Password123']);
    }

    public function test_a_manager_adds_staff_to_his_team_and_lists_it(): void
    {
        Sanctum::actingAs($this->taha);

        $this->postJson("/api/my-team/{$this->huda->id}")->assertOk()->assertJsonPath('changed', true);
        $this->postJson("/api/my-team/{$this->huda->id}")->assertOk()->assertJsonPath('changed', false);

        $this->assertDatabaseHas('manager_staff', ['manager_id' => $this->taha->id, 'staff_id' => $this->huda->id, 'added_by' => $this->taha->id]);
        $this->assertSame(1, \App\Models\AuditLog::where('action', 'staff.team_added')->count());

        $res = $this->getJson('/api/my-team')->assertOk();
        $this->assertSame([$this->huda->id], collect($res->json('team'))->pluck('id')->all());
        $this->assertSame([$this->omar->id], collect($res->json('others'))->pluck('id')->all());
    }

    public function test_the_same_staff_member_can_be_in_two_managers_teams(): void
    {
        Sanctum::actingAs($this->taha);
        $this->postJson("/api/my-team/{$this->huda->id}")->assertOk();
        Sanctum::actingAs($this->mariam);
        $this->postJson("/api/my-team/{$this->huda->id}")->assertOk();

        $this->assertSame(2, $this->huda->staffManagers()->count());

        $others = collect($this->getJson('/api/my-team')->json('team'))->firstWhere('id', $this->huda->id);
        $this->assertSame([$this->taha->id], collect($others['other_teams'])->pluck('manager_id')->all());

        // Seen from Taha's "others" list, Omar is free; Huda is in his team, not "others".
        Sanctum::actingAs($this->taha);
        $this->assertSame([$this->omar->id], collect($this->getJson('/api/my-team')->json('others'))->pluck('id')->all());
    }

    public function test_team_shows_assignments_on_his_clients_with_permissions(): void
    {
        $this->taha->managerTeam()->attach($this->huda->id);
        $this->w1->staff()->attach($this->huda->id, ['assigned_by' => $this->taha->id, 'permissions' => json_encode(['can_view_chat' => true, 'can_send_chat' => false, 'can_view_approvals' => true, 'can_request_approvals' => true, 'can_view_meetings' => true, 'can_join_meetings' => true])]);
        $this->w2->staff()->attach($this->huda->id, ['assigned_by' => $this->taha->id]);
        // Her assignment on Mariam's client is not part of Taha's view.
        $this->wm->staff()->attach($this->huda->id, ['assigned_by' => $this->superAdmin->id]);
        Sanctum::actingAs($this->taha);

        $member = collect($this->getJson('/api/my-team')->assertOk()->json('team'))->firstWhere('id', $this->huda->id);

        $this->assertCount(2, $member['assignments']);
        $nile = collect($member['assignments'])->firstWhere('workspace_id', $this->w1->id);
        $this->assertFalse($nile['permissions']['can_send_chat']);
        $this->assertTrue($nile['permissions']['can_view_chat']);
        $delta = collect($member['assignments'])->firstWhere('workspace_id', $this->w2->id);
        $this->assertNotContains(false, $delta['permissions'], 'null permissions means all allowed');
    }

    public function test_removal_impact_counts_and_names_only_his_clients(): void
    {
        $this->taha->managerTeam()->attach($this->huda->id);
        $this->w1->staff()->attach($this->huda->id, ['assigned_by' => $this->taha->id]);
        $this->w2->staff()->attach($this->huda->id, ['assigned_by' => $this->taha->id]);
        $this->wm->staff()->attach($this->huda->id, ['assigned_by' => $this->superAdmin->id]);
        Sanctum::actingAs($this->taha);

        $res = $this->getJson("/api/my-team/{$this->huda->id}/removal-impact")->assertOk()->assertJsonPath('count', 2);
        $this->assertEqualsCanonicalizing(['Nile Bakery', 'Delta Foods'], $res->json('clients'));
    }

    public function test_removing_unassigns_from_his_clients_only(): void
    {
        $this->taha->managerTeam()->attach($this->huda->id);
        $this->mariam->managerTeam()->attach($this->huda->id);
        $this->w1->staff()->attach($this->huda->id, ['assigned_by' => $this->taha->id]);
        $this->w2->staff()->attach($this->huda->id, ['assigned_by' => $this->taha->id]);
        $this->wm->staff()->attach($this->huda->id, ['assigned_by' => $this->mariam->id]);
        Sanctum::actingAs($this->taha);

        $this->deleteJson("/api/my-team/{$this->huda->id}")->assertOk()->assertJsonPath('unassigned_count', 2);

        $this->assertDatabaseMissing('manager_staff', ['manager_id' => $this->taha->id, 'staff_id' => $this->huda->id]);
        $this->assertDatabaseHas('manager_staff', ['manager_id' => $this->mariam->id, 'staff_id' => $this->huda->id]);
        $this->assertSame(0, $this->w1->staff()->count());
        $this->assertSame(0, $this->w2->staff()->count());
        $this->assertSame(1, $this->wm->staff()->count(), "another manager's assignment is untouched");

        $this->assertSame(2, \App\Models\AuditLog::where('action', 'staff.unassigned')->count());
        $this->assertSame(1, \App\Models\AuditLog::where('action', 'staff.team_removed')->count());
    }

    public function test_removing_someone_not_in_the_team_is_404(): void
    {
        Sanctum::actingAs($this->taha);

        $this->deleteJson("/api/my-team/{$this->huda->id}")->assertNotFound();
        $this->getJson("/api/my-team/{$this->huda->id}/removal-impact")->assertNotFound();
    }

    public function test_only_active_staff_members_can_join_a_team(): void
    {
        $archived = $this->member('Old');
        $archived->forceFill(['is_active' => false, 'archived_at' => now()])->save();
        Sanctum::actingAs($this->taha);

        $this->postJson("/api/my-team/{$archived->id}")->assertUnprocessable();
        $this->postJson("/api/my-team/{$this->mariam->id}")->assertNotFound();
        $this->assertSame(0, $this->taha->managerTeam()->count());
    }

    public function test_only_account_managers_can_use_my_team(): void
    {
        $assistant = User::factory()->create(['role' => User::ROLE_MANAGER_ASSISTANT, 'parent_manager_id' => $this->taha->id, 'assistant_permissions' => ['can_view_clients' => true], 'is_active' => true]);

        foreach ([$this->superAdmin, $assistant, $this->huda, $this->c1] as $actor) {
            Sanctum::actingAs($actor);
            $this->getJson('/api/my-team')->assertForbidden();
            $this->postJson("/api/my-team/{$this->omar->id}")->assertForbidden();
        }

        $this->assertSame(0, \DB::table('manager_staff')->count());
    }

    public function test_company_staff_list_shows_which_teams_each_member_is_in(): void
    {
        $this->taha->managerTeam()->attach($this->huda->id);
        $this->mariam->managerTeam()->attach($this->huda->id);
        Sanctum::actingAs($this->superAdmin);

        $rows = collect($this->getJson('/api/company-staff')->assertOk()->json('staff'));

        $this->assertEqualsCanonicalizing(['Taha', 'Mariam'], collect($rows->firstWhere('id', $this->huda->id)['teams'])->pluck('name')->all());
        $this->assertSame([], $rows->firstWhere('id', $this->omar->id)['teams']);
    }
}
