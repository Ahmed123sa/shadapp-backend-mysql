<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Payment;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 26 Sept 2026 — GET /dashboard/pending-approvals (pending-approvals-plan.md
 * ك1). The SA/AM home screens' "Pending Approvals" list used to only show
 * approval-request items (and, on the AM home, didn't exist at all) even
 * though the badge/card next to it always summed three item types
 * (DashboardScope::pendingApprovalsTotal()). This is the list that matches
 * that count exactly — the count-parity test below is the single most
 * important one here, since a list/count mismatch is the exact bug this
 * plan exists to close (see that method's docblock for the history).
 */
class PendingApprovalsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function clientFor(User $manager): Client
    {
        return Client::factory()->create(['manager_id' => $manager->id]);
    }

    private function workspaceFor(Client $client, User $manager): Workspace
    {
        return Workspace::factory()->create([
            'client_id' => $client->id,
            'manager_id' => $manager->id,
        ]);
    }

    public function test_counts_match_the_badge_and_stats_endpoints_for_the_same_user(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $client = $this->clientFor($manager);
        $workspace = $this->workspaceFor($client, $manager);

        Contract::factory()->create(['workspace_id' => $workspace->id, 'status' => 'sent']);
        Contract::factory()->create(['workspace_id' => $workspace->id, 'status' => 'client_approved']);
        Approval::factory()->create(['workspace_id' => $workspace->id, 'requested_by' => $manager->id]);
        Payment::factory()->create(['workspace_id' => $workspace->id, 'client_id' => $client->id, 'status' => 'pending']);

        $list = $this->actingAs($manager)->getJson('/api/dashboard/pending-approvals')->assertOk();
        $badges = $this->actingAs($manager)->getJson('/api/badge-counts')->assertOk();
        $stats = $this->actingAs($manager)->getJson('/api/dashboard/stats')->assertOk();

        $this->assertSame(4, $list->json('counts.total'));
        $this->assertSame($badges->json('approvals'), $list->json('counts.total'));
        $this->assertSame($stats->json('approvals.total'), $list->json('counts.total'));
    }

    public function test_items_are_split_into_awaiting_you_and_awaiting_client(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $client = $this->clientFor($manager);
        $workspace = $this->workspaceFor($client, $manager);

        $sent = Contract::factory()->create(['workspace_id' => $workspace->id, 'status' => 'sent', 'title' => 'Sent Deal']);
        $clientApproved = Contract::factory()->create(['workspace_id' => $workspace->id, 'status' => 'client_approved', 'title' => 'Client Approved Deal']);
        $approval = Approval::factory()->create(['workspace_id' => $workspace->id, 'requested_by' => $manager->id]);
        $payment = Payment::factory()->create(['workspace_id' => $workspace->id, 'client_id' => $client->id, 'status' => 'pending']);

        $response = $this->actingAs($manager)->getJson('/api/dashboard/pending-approvals')->assertOk();

        $this->assertCount(1, $response->json('awaiting_you.contracts'));
        $this->assertSame($clientApproved->id, $response->json('awaiting_you.contracts.0.id'));
        $this->assertCount(1, $response->json('awaiting_you.payments'));
        $this->assertSame($payment->id, $response->json('awaiting_you.payments.0.id'));

        $this->assertCount(1, $response->json('awaiting_client.contracts'));
        $this->assertSame($sent->id, $response->json('awaiting_client.contracts.0.id'));
        $this->assertCount(1, $response->json('awaiting_client.approvals'));
        $this->assertSame($approval->id, $response->json('awaiting_client.approvals.0.id'));
    }

    public function test_excludes_statuses_that_are_not_pending(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $client = $this->clientFor($manager);
        $workspace = $this->workspaceFor($client, $manager);

        Contract::factory()->create(['workspace_id' => $workspace->id, 'status' => 'draft']);
        Contract::factory()->create(['workspace_id' => $workspace->id, 'status' => 'company_approved']);
        Contract::factory()->create(['workspace_id' => $workspace->id, 'status' => 'completed']);
        Approval::factory()->create(['workspace_id' => $workspace->id, 'requested_by' => $manager->id, 'status' => 'approved']);
        Payment::factory()->create(['workspace_id' => $workspace->id, 'client_id' => $client->id, 'status' => 'approved']);

        $response = $this->actingAs($manager)->getJson('/api/dashboard/pending-approvals')->assertOk();

        $this->assertSame(0, $response->json('counts.total'));
        $this->assertCount(0, $response->json('awaiting_you.contracts'));
        $this->assertCount(0, $response->json('awaiting_you.payments'));
        $this->assertCount(0, $response->json('awaiting_client.contracts'));
        $this->assertCount(0, $response->json('awaiting_client.approvals'));
    }

    public function test_an_account_manager_only_sees_their_own_clients_items(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $other = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $ownClient = $this->clientFor($manager);
        $ownWorkspace = $this->workspaceFor($ownClient, $manager);
        $otherClient = $this->clientFor($other);
        $otherWorkspace = $this->workspaceFor($otherClient, $other);

        Contract::factory()->create(['workspace_id' => $ownWorkspace->id, 'status' => 'sent']);
        Contract::factory()->create(['workspace_id' => $otherWorkspace->id, 'status' => 'sent']);

        $response = $this->actingAs($manager)->getJson('/api/dashboard/pending-approvals')->assertOk();

        $this->assertSame(1, $response->json('counts.total'));
        $this->assertCount(1, $response->json('awaiting_client.contracts'));
    }

    public function test_a_super_admin_sees_every_managers_items(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $managerA = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $managerB = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $clientA = $this->clientFor($managerA);
        $workspaceA = $this->workspaceFor($clientA, $managerA);
        $clientB = $this->clientFor($managerB);
        $workspaceB = $this->workspaceFor($clientB, $managerB);

        Contract::factory()->create(['workspace_id' => $workspaceA->id, 'status' => 'sent']);
        Contract::factory()->create(['workspace_id' => $workspaceB->id, 'status' => 'sent']);

        $response = $this->actingAs($admin)->getJson('/api/dashboard/pending-approvals')->assertOk();

        $this->assertSame(2, $response->json('counts.total'));
    }

    public function test_each_group_is_capped_but_counts_stay_uncapped(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $client = $this->clientFor($manager);
        $workspace = $this->workspaceFor($client, $manager);

        Contract::factory()->count(55)->create(['workspace_id' => $workspace->id, 'status' => 'sent']);

        $response = $this->actingAs($manager)->getJson('/api/dashboard/pending-approvals?limit=50')->assertOk();

        $this->assertSame(55, $response->json('counts.total'));
        $this->assertSame(55, $response->json('counts.pending_contracts'));
        $this->assertCount(50, $response->json('awaiting_client.contracts'));
    }

    public function test_a_custom_limit_is_respected(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $client = $this->clientFor($manager);
        $workspace = $this->workspaceFor($client, $manager);

        Contract::factory()->count(10)->create(['workspace_id' => $workspace->id, 'status' => 'sent']);

        $response = $this->actingAs($manager)->getJson('/api/dashboard/pending-approvals?limit=3')->assertOk();

        $this->assertCount(3, $response->json('awaiting_client.contracts'));
        $this->assertSame(10, $response->json('counts.total'));
    }

    // Oldest first: the longest-waiting contract must not fall off the
    // capped list the way it would under a "most recent N" ordering
    // (pending-approvals-plan.md ن3).
    public function test_items_are_ordered_oldest_first(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $client = $this->clientFor($manager);
        $workspace = $this->workspaceFor($client, $manager);

        $older = Contract::factory()->create(['workspace_id' => $workspace->id, 'status' => 'sent', 'updated_at' => now()->subDays(5)]);
        $newer = Contract::factory()->create(['workspace_id' => $workspace->id, 'status' => 'sent', 'updated_at' => now()->subDay()]);

        $response = $this->actingAs($manager)->getJson('/api/dashboard/pending-approvals')->assertOk();

        $this->assertSame($older->id, $response->json('awaiting_client.contracts.0.id'));
        $this->assertSame($newer->id, $response->json('awaiting_client.contracts.1.id'));
    }

    public function test_each_item_includes_the_clients_uuid_for_deep_linking(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $client = $this->clientFor($manager);
        $workspace = $this->workspaceFor($client, $manager);

        Contract::factory()->create(['workspace_id' => $workspace->id, 'status' => 'sent']);

        $response = $this->actingAs($manager)->getJson('/api/dashboard/pending-approvals')->assertOk();

        $this->assertSame($client->uuid, $response->json('awaiting_client.contracts.0.client.uuid'));
        $this->assertSame($client->company_name, $response->json('awaiting_client.contracts.0.client.company_name'));
    }

    // pending-approvals-plan.md ك5 — the mobile approvals queue renders a
    // business/individual badge for every client-linked item; client_type
    // must travel with the client summary or that badge silently disappears
    // once mobile migrates onto this endpoint.
    public function test_each_item_includes_the_clients_type_for_the_mobile_badge(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $client = $this->clientFor($manager);
        $client->update(['client_type' => 'individual']);
        $workspace = $this->workspaceFor($client, $manager);

        Contract::factory()->create(['workspace_id' => $workspace->id, 'status' => 'sent']);

        $response = $this->actingAs($manager)->getJson('/api/dashboard/pending-approvals')->assertOk();

        $this->assertSame('individual', $response->json('awaiting_client.contracts.0.client.client_type'));
    }

    // 403/401 refusals for client/sub-user/unauthenticated tokens are
    // covered by StaffOnlyRouteTest's staffOnlyRoutes() data provider,
    // which this route was added to.
}
