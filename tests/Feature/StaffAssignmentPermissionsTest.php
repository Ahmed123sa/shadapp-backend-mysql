<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * PLAN_2_MANAGER_TEAMS.md ف١ — team rule (ق٧), per-client permissions (ق٣)
 * and what survives a client transfer (ق٤).
 */
class StaffAssignmentPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private User $taha;
    private User $mariam;
    private User $huda;
    private User $omar;
    private Client $c1;
    private Workspace $w1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $this->taha = User::factory()->create(['name' => 'Taha', 'role' => User::ROLE_ACCOUNT_MANAGER, 'super_admin_id' => $this->superAdmin->id, 'is_active' => true]);
        $this->mariam = User::factory()->create(['name' => 'Mariam', 'role' => User::ROLE_ACCOUNT_MANAGER, 'super_admin_id' => $this->superAdmin->id, 'is_active' => true]);
        $this->huda = User::factory()->create(['name' => 'Huda', 'role' => User::ROLE_STAFF_MEMBER, 'is_active' => true]);
        $this->omar = User::factory()->create(['name' => 'Omar', 'role' => User::ROLE_STAFF_MEMBER, 'is_active' => true]);

        $this->c1 = Client::factory()->create(['manager_id' => $this->taha->id]);
        $this->w1 = Workspace::factory()->create(['client_id' => $this->c1->id, 'manager_id' => $this->taha->id, 'status' => 'active']);
        $this->taha->managerTeam()->attach($this->huda->id);
    }

    private function url(?User $m = null): string
    {
        return "/api/workspaces/{$this->w1->id}/staff/" . ($m ?? $this->huda)->id;
    }

    public function test_a_manager_cannot_assign_someone_outside_his_team(): void
    {
        Sanctum::actingAs($this->taha);

        $this->putJson($this->url($this->omar))->assertUnprocessable();
        $this->assertSame(0, $this->w1->staff()->count());
    }

    public function test_the_super_admin_assigns_anyone_outside_any_team(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $this->putJson($this->url($this->omar))->assertOk()->assertJsonPath('changed', true);
        $this->assertSame(1, $this->w1->staff()->count());
    }

    public function test_a_default_assignment_stores_null_and_means_everything(): void
    {
        Sanctum::actingAs($this->taha);

        $res = $this->putJson($this->url())->assertOk();

        $this->assertNull(\DB::table('workspace_staff')->where('user_id', $this->huda->id)->value('permissions'));
        foreach (User::STAFF_PERMISSION_KEYS as $key) {
            $res->assertJsonPath("staff.permissions.$key", true);
        }
        $this->assertTrue($this->w1->staffCan($this->huda, 'can_send_chat'));
    }

    public function test_assigning_with_permissions_stores_them(): void
    {
        Sanctum::actingAs($this->taha);

        $this->putJson($this->url(), ['permissions' => ['can_send_chat' => false, 'can_join_meetings' => false]])
            ->assertOk()
            ->assertJsonPath('staff.permissions.can_view_chat', true)
            ->assertJsonPath('staff.permissions.can_send_chat', false)
            ->assertJsonPath('staff.permissions.can_join_meetings', false);

        $this->assertFalse($this->w1->staffCan($this->huda, 'can_send_chat'));
        $this->assertTrue($this->w1->staffCan($this->huda, 'can_view_chat'));
    }

    public function test_patch_updates_permissions_and_audits(): void
    {
        $this->w1->staff()->attach($this->huda->id, ['assigned_by' => $this->taha->id]);
        Sanctum::actingAs($this->taha);

        $this->patchJson($this->url() . '/permissions', ['permissions' => ['can_request_approvals' => false]])
            ->assertOk()
            ->assertJsonPath('staff.permissions.can_request_approvals', false)
            ->assertJsonPath('staff.permissions.can_view_approvals', true);

        $this->assertSame(1, \App\Models\AuditLog::where('action', 'staff.permissions_updated')->count());

        // Back to everything → stored as NULL again.
        $this->patchJson($this->url() . '/permissions', ['permissions' => ['can_request_approvals' => true]])->assertOk();
        $this->assertNull(\DB::table('workspace_staff')->where('user_id', $this->huda->id)->value('permissions'));
    }

    public function test_an_action_without_its_view_is_switched_off_too(): void
    {
        $this->w1->staff()->attach($this->huda->id, ['assigned_by' => $this->taha->id]);
        Sanctum::actingAs($this->taha);

        $this->patchJson($this->url() . '/permissions', ['permissions' => ['can_view_chat' => false]])
            ->assertOk()
            ->assertJsonPath('staff.permissions.can_view_chat', false)
            ->assertJsonPath('staff.permissions.can_send_chat', false);

        // Asking for send while view is off does not turn it on either.
        $this->patchJson($this->url() . '/permissions', ['permissions' => ['can_send_chat' => true]])
            ->assertJsonPath('staff.permissions.can_send_chat', false);
    }

    public function test_patch_needs_an_existing_assignment_and_valid_input(): void
    {
        Sanctum::actingAs($this->taha);

        $this->patchJson($this->url() . '/permissions', ['permissions' => ['can_send_chat' => false]])->assertNotFound();

        $this->w1->staff()->attach($this->huda->id, ['assigned_by' => $this->taha->id]);
        $this->patchJson($this->url() . '/permissions', [])->assertUnprocessable();
        $this->patchJson($this->url() . '/permissions', ['permissions' => ['can_send_chat' => 'maybe']])->assertUnprocessable();
    }

    public function test_only_the_workspace_manager_or_super_admin_can_patch(): void
    {
        $this->w1->staff()->attach($this->huda->id, ['assigned_by' => $this->taha->id]);

        foreach ([$this->mariam, $this->huda, $this->c1] as $actor) {
            Sanctum::actingAs($actor);
            $this->patchJson($this->url() . '/permissions', ['permissions' => ['can_send_chat' => false]])->assertForbidden();
        }
        $this->assertNull(\DB::table('workspace_staff')->where('user_id', $this->huda->id)->value('permissions'));

        Sanctum::actingAs($this->superAdmin);
        $this->patchJson($this->url() . '/permissions', ['permissions' => ['can_send_chat' => false]])->assertOk();
    }

    public function test_a_transferred_client_keeps_the_assignment_and_flags_it_not_in_my_team(): void
    {
        $this->w1->staff()->attach($this->huda->id, ['assigned_by' => $this->taha->id]);
        $this->c1->update(['manager_id' => $this->mariam->id]);
        $this->w1->update(['manager_id' => $this->mariam->id]);

        // Still assigned, still allowed.
        $this->assertTrue($this->w1->fresh()->hasStaffMember($this->huda));

        Sanctum::actingAs($this->mariam);
        $rows = collect($this->getJson("/api/workspaces/{$this->w1->id}/staff")->assertOk()->json('staff'));
        $row = $rows->firstWhere('id', $this->huda->id);
        $this->assertTrue($row['assigned']);
        $this->assertFalse($row['in_my_team']);
        // Nobody else from outside Mariam's team shows up.
        $this->assertNull($rows->firstWhere('id', $this->omar->id));

        // She can drop her, or add her to her team.
        $this->postJson("/api/my-team/{$this->huda->id}")->assertOk();
        $row = collect($this->getJson("/api/workspaces/{$this->w1->id}/staff")->json('staff'))->firstWhere('id', $this->huda->id);
        $this->assertTrue($row['in_my_team']);
    }

    public function test_a_managers_staff_list_is_his_team_while_the_super_admin_sees_everyone(): void
    {
        Sanctum::actingAs($this->taha);
        $ids = collect($this->getJson("/api/workspaces/{$this->w1->id}/staff")->assertOk()->json('staff'))->pluck('id')->all();
        $this->assertSame([$this->huda->id], $ids);

        Sanctum::actingAs($this->superAdmin);
        $ids = collect($this->getJson("/api/workspaces/{$this->w1->id}/staff")->json('staff'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$this->huda->id, $this->omar->id], $ids);
    }

    public function test_the_default_assignment_made_before_permissions_existed_still_works(): void
    {
        // A row exactly as the old code wrote it: no permissions column value.
        \DB::table('workspace_staff')->insert([
            'workspace_id' => $this->w1->id, 'user_id' => $this->omar->id, 'assigned_by' => $this->superAdmin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach (User::STAFF_PERMISSION_KEYS as $key) {
            $this->assertTrue($this->w1->staffCan($this->omar, $key), $key);
        }
    }
}
