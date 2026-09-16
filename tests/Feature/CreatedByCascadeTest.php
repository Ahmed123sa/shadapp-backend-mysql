<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Meeting;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the fix in migration 2026_09_16_000001_fix_created_by_cascades.php
 * (DATA_SAFETY_PLAN.md §2.4): contracts.created_by, approvals.requested_by
 * and meetings.created_by were wired with cascadeOnDelete() instead of
 * nullOnDelete(). Deleting any user who had ever created a contract,
 * requested an approval or created a meeting — not just a manager assigned
 * to that client — silently deleted that record too, anywhere in the
 * system. The fix makes deleting a user null the column instead.
 *
 * Deliberately gives the "creator" and the workspace's current manager two
 * different users in every test here. workspaces.manager_id and
 * contracts.workspace_id are (correctly) cascadeOnDelete() — deleting the
 * manager who currently owns a workspace is supposed to take that
 * workspace, and everything under it, with it. That's a different FK path
 * from the one this migration fixes. The real-world case this migration
 * protects against is a client transfer (see ClientTransferTest's "contract
 * created_by does not change after transfer"): the creator keeps authoring
 * credit on old contracts even after the client — and the workspace —
 * moves to a different manager. If the original creator is later removed
 * while no longer managing anything, only their own created_by/requested_by
 * columns should be affected, not the workspace or the record itself.
 *
 * Mirrors tests/Feature/CreatedByCascadeTest.php from shadapp-backend
 * (Postgres) verbatim.
 */
class CreatedByCascadeTest extends TestCase
{
    use RefreshDatabase;

    private function makeWorkspace(User $manager): Workspace
    {
        $client = Client::factory()->create(['manager_id' => $manager->id]);

        return Workspace::factory()->create([
            'client_id' => $client->id,
            'manager_id' => $manager->id,
        ]);
    }

    public function test_deleting_a_user_does_not_delete_their_contracts(): void
    {
        $creator = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $currentManager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $workspace = $this->makeWorkspace($currentManager);
        $contract = Contract::factory()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $creator->id,
        ]);

        $creator->delete();

        $this->assertNotNull(Contract::find($contract->id));
    }

    public function test_deleting_a_user_nulls_the_contracts_created_by_column(): void
    {
        $creator = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $currentManager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $workspace = $this->makeWorkspace($currentManager);
        $contract = Contract::factory()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $creator->id,
        ]);

        $creator->delete();

        $this->assertNull($contract->fresh()->created_by);
    }

    public function test_deleting_a_user_does_not_delete_their_approvals_and_nulls_requested_by(): void
    {
        $requester = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $currentManager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $workspace = $this->makeWorkspace($currentManager);
        $approval = Approval::factory()->create([
            'workspace_id' => $workspace->id,
            'approvable_type' => 'workspace',
            'approvable_id' => $workspace->id,
            'requested_by' => $requester->id,
        ]);

        $requester->delete();

        $fresh = Approval::find($approval->id);
        $this->assertNotNull($fresh);
        $this->assertNull($fresh->requested_by);
    }

    public function test_deleting_a_user_does_not_delete_their_meetings_and_nulls_created_by(): void
    {
        $creator = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $currentManager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $workspace = $this->makeWorkspace($currentManager);
        // No MeetingFactory exists in this codebase — created directly, same
        // as the model's own fillable list.
        $meeting = Meeting::create([
            'workspace_id' => $workspace->id,
            'title' => 'Kickoff call',
            'scheduled_at' => now()->addDay(),
            'duration_minutes' => 30,
            'status' => 'scheduled',
            'created_by' => $creator->id,
        ]);

        $creator->delete();

        $fresh = Meeting::find($meeting->id);
        $this->assertNotNull($fresh);
        $this->assertNull($fresh->created_by);
    }

    public function test_viewing_a_contract_with_a_null_creator_does_not_crash(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $creator = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $currentManager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $workspace = $this->makeWorkspace($currentManager);
        $contract = Contract::factory()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $creator->id,
        ]);

        $creator->delete();
        $this->assertNull($contract->fresh()->created_by);

        $response = $this->actingAs($admin, 'sanctum')->getJson("/api/contracts/{$contract->id}");

        $response->assertStatus(200);
        $this->assertNull($response->json('contract.creator'));
    }
}
