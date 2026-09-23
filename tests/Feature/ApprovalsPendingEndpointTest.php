<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\Client;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 23 Sept 2026 — GET /approvals/pending used to call $user->workspaces(),
 * a relation User doesn't have, so it 500'd for every account manager (only
 * super admins worked). Mobile's aggregate Approvals screen now relies on
 * this endpoint, so it has to be scoped correctly: an AM sees pending
 * approvals from their own workspaces only, same scope as the Approvals
 * badge in DashboardController::amCounts().
 */
class ApprovalsPendingEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function workspaceFor(User $manager): Workspace
    {
        $client = Client::factory()->create(['manager_id' => $manager->id]);

        return Workspace::factory()->create([
            'client_id' => $client->id,
            'manager_id' => $manager->id,
        ]);
    }

    public function test_an_account_manager_gets_pending_approvals_from_their_own_workspaces_only(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $other = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $mine = $this->workspaceFor($manager);
        $theirs = $this->workspaceFor($other);

        $pending = Approval::factory()->create(['workspace_id' => $mine->id, 'requested_by' => $manager->id]);
        Approval::factory()->create(['workspace_id' => $mine->id, 'requested_by' => $manager->id, 'status' => 'approved']);
        Approval::factory()->create(['workspace_id' => $theirs->id, 'requested_by' => $other->id]);

        $ids = collect(
            $this->actingAs($manager)->getJson('/api/approvals/pending')->assertOk()->json('approvals')
        )->pluck('id')->all();

        $this->assertSame([$pending->id], $ids);
    }

    public function test_a_super_admin_gets_pending_approvals_from_every_workspace(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $managerA = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $managerB = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);

        Approval::factory()->create(['workspace_id' => $this->workspaceFor($managerA)->id, 'requested_by' => $managerA->id]);
        Approval::factory()->create(['workspace_id' => $this->workspaceFor($managerB)->id, 'requested_by' => $managerB->id]);

        $approvals = $this->actingAs($admin)->getJson('/api/approvals/pending')->assertOk()->json('approvals');

        $this->assertCount(2, $approvals);
    }

    public function test_each_pending_approval_carries_its_workspace_and_client(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $workspace = $this->workspaceFor($manager);
        Approval::factory()->create(['workspace_id' => $workspace->id, 'requested_by' => $manager->id]);

        $approval = $this->actingAs($manager)->getJson('/api/approvals/pending')->assertOk()->json('approvals.0');

        $this->assertEquals($workspace->id, $approval['workspace_id']);
        $this->assertEquals($workspace->client_id, $approval['workspace']['client']['id']);
    }
}
