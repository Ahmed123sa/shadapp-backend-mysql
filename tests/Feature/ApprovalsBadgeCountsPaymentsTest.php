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
 * 23 Sept 2026 — the Approvals badge (/badge-counts 'approvals') opens the
 * mobile Approvals screen, which lists pending contracts, pending approval
 * requests and payments awaiting approval (status 'pending'). The badge used
 * to count only the first two, so a waiting payment made the list longer
 * than the badge. It now counts all three, using the same payment filter as
 * GET /payments/pending.
 */
class ApprovalsBadgeCountsPaymentsTest extends TestCase
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

    private function seedPendingItems(Workspace $workspace, User $requester): void
    {
        Contract::factory()->create(['workspace_id' => $workspace->id, 'status' => 'sent']);
        Approval::factory()->create(['workspace_id' => $workspace->id, 'requested_by' => $requester->id]);
        Payment::factory()->create(['workspace_id' => $workspace->id, 'client_id' => $workspace->client_id, 'status' => 'pending']);
        // Neither of these is waiting on the manager, so neither counts.
        Payment::factory()->create(['workspace_id' => $workspace->id, 'client_id' => $workspace->client_id, 'status' => 'approved']);
        Payment::factory()->create(['workspace_id' => $workspace->id, 'client_id' => $workspace->client_id, 'status' => 'rejected']);
    }

    public function test_an_account_managers_approvals_badge_counts_pending_payments_in_their_own_workspaces(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $other = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $this->seedPendingItems($this->workspaceFor($manager), $manager);
        $this->seedPendingItems($this->workspaceFor($other), $other);

        $response = $this->actingAs($manager)->getJson('/api/badge-counts')->assertOk();

        // 1 contract + 1 approval request + 1 pending payment, own workspace only.
        $this->assertSame(3, $response->json('approvals'));
    }

    public function test_a_super_admins_approvals_badge_counts_pending_payments_company_wide(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $managerA = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $managerB = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $this->seedPendingItems($this->workspaceFor($managerA), $managerA);
        $this->seedPendingItems($this->workspaceFor($managerB), $managerB);

        $response = $this->actingAs($admin)->getJson('/api/badge-counts')->assertOk();

        $this->assertSame(6, $response->json('approvals'));
    }

    // The separate 'payments' key is a different, broader count and must
    // not change.
    public function test_the_payments_key_is_unchanged(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $this->seedPendingItems($this->workspaceFor($manager), $manager);

        $response = $this->actingAs($manager)->getJson('/api/badge-counts')->assertOk();

        $this->assertSame(1, $response->json('payments'));
    }
}
