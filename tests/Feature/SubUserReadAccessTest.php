<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Meeting;
use App\Models\SubUser;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 19 Sept 2026 — every one of these was 403ing in production for a
 * legitimate sub-user reading their own company's data, despite
 * ScopeWorkspace's tenant-isolation check passing correctly. The bug lived
 * one layer deeper: WorkspacePolicy::view(), ContractPolicy::viewAny()/
 * view(), PaymentPolicy::viewAny()/view(), MeetingPolicy::viewAny() and
 * FileEntryPolicy::viewAny() all had no SubUser branch at all —
 * ApprovalPolicy::viewAny() was the sole exception, which is exactly why
 * /workspaces/{id}/approvals was the only tab that didn't break in the
 * live bug report that led here.
 *
 * TenantIsolationTest already covers the *negative* direction (a sub-user
 * reaching into another tenant's workspace) extremely well; what was
 * missing — and let this ship — was any *positive* test proving a sub-user
 * can read their own workspace's data at all. This file is that missing
 * positive coverage, mirrored one-for-one against shadapp-backend-mysql.
 *
 * Per DATA_SAFETY_PLAN.md §7.3, `can_view_*` permission flags are
 * deliberately UI-only and not re-checked by these policies — tenant
 * membership (client_id match) is the only gate here, same as for a
 * Client. So every sub-user below is created with an empty permissions
 * array on purpose, to prove these reads work regardless of view flags.
 */
class SubUserReadAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private Client $client;
    private Workspace $workspace;
    private SubUser $subUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $this->client = Client::factory()->create(['manager_id' => $this->manager->id]);
        $this->workspace = Workspace::factory()->create([
            'client_id' => $this->client->id,
            'manager_id' => $this->manager->id,
        ]);
        $this->subUser = SubUser::factory()->create([
            'client_id' => $this->client->id,
            'permissions' => [],
        ]);
    }

    private function resetAuth(): void
    {
        $this->app->make('auth')->forgetGuards();
    }

    private function actingAsSubUser(SubUser $subUser): self
    {
        $this->resetAuth();
        return $this->actingAs($subUser, 'sub_user');
    }

    private function actingAsClient(Client $client): self
    {
        $this->resetAuth();
        return $this->actingAs($client, 'client');
    }

    public function test_a_sub_user_can_view_their_own_workspace(): void
    {
        $this->actingAsSubUser($this->subUser)
            ->getJson("/api/workspaces/{$this->workspace->id}")
            ->assertOk();
    }

    public function test_a_sub_user_can_list_their_workspaces_contracts(): void
    {
        Contract::factory()->create(['workspace_id' => $this->workspace->id]);

        $this->actingAsSubUser($this->subUser)
            ->getJson("/api/workspaces/{$this->workspace->id}/contracts")
            ->assertOk();
    }

    public function test_a_sub_user_can_list_their_workspaces_payments(): void
    {
        $this->actingAsSubUser($this->subUser)
            ->getJson("/api/workspaces/{$this->workspace->id}/payments")
            ->assertOk();
    }

    public function test_a_sub_user_can_list_their_workspaces_meetings(): void
    {
        Meeting::create([
            'workspace_id' => $this->workspace->id,
            'title' => 'Kickoff',
            'scheduled_at' => now()->addDay(),
            'duration_minutes' => 30,
            'status' => 'scheduled',
            'created_by' => $this->manager->id,
        ]);

        $this->actingAsSubUser($this->subUser)
            ->getJson("/api/workspaces/{$this->workspace->id}/meetings")
            ->assertOk();
    }

    public function test_a_sub_user_can_list_their_workspaces_files(): void
    {
        $this->actingAsSubUser($this->subUser)
            ->getJson("/api/workspaces/{$this->workspace->id}/files")
            ->assertOk();
    }

    public function test_a_sub_user_can_list_their_workspaces_approvals(): void
    {
        $this->actingAsSubUser($this->subUser)
            ->getJson("/api/workspaces/{$this->workspace->id}/approvals")
            ->assertOk();
    }

    // ApprovalController::show() (`GET /approvals/{approval}`) was
    // deliberately left out here after a closer look: it's a staff-only
    // endpoint by design — neither the dashboard nor the mobile app ever
    // call it for a client/sub-user session (their approval flow goes
    // through /chat/{chatMessage}/respond, see the comment above the
    // /approvals/{approval}/respond route in routes/api.php). It also sits
    // in the plain 'auth:sanctum' group, not the dual-auth one. Widening
    // ApprovalPolicy::view() to admit Client/SubUser would grant a
    // capability nothing in this codebase asks for, so that policy is left
    // untouched — unlike Workspace/Contract/Payment/Meeting/File above,
    // whose live 403s were confirmed against routes both apps actually call.

    /**
     * Sanity check the other direction still holds after adding the
     * SubUser branch: a sub-user belonging to a different client must
     * still be rejected, not just any SubUser instance.
     */
    public function test_a_sub_user_from_another_client_still_cannot_view_this_workspace(): void
    {
        $otherClient = Client::factory()->create(['manager_id' => $this->manager->id]);
        $otherSubUser = SubUser::factory()->create(['client_id' => $otherClient->id, 'permissions' => []]);

        $this->actingAsSubUser($otherSubUser)
            ->getJson("/api/workspaces/{$this->workspace->id}")
            ->assertStatus(403);
    }
}
