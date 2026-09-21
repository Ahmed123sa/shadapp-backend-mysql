<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Payment;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 21 Sept 2026 — AuditController::reports() never sent a `manager_stats`
 * key, so the dashboard's and mobile's "top managers" leaderboard always
 * fell back to a fabricated per-row revenue (the report total divided by a
 * rank-based number) instead of showing real data. This is the backend half
 * of the fix: a genuine per-manager rollup, ranked by revenue.
 */
class ManagerStatsReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
    }

    private function managerWithData(int $superAdminId, string $name, string $approvedAmount, int $extraClients = 0, int $contracts = 0): User
    {
        $manager = User::factory()->create([
            'role' => User::ROLE_ACCOUNT_MANAGER,
            'super_admin_id' => $superAdminId,
            'name' => $name,
        ]);

        $client = Client::factory()->create(['manager_id' => $manager->id]);
        $workspace = Workspace::factory()->create([
            'client_id' => $client->id,
            'manager_id' => $manager->id,
        ]);

        Payment::factory()->create([
            'workspace_id' => $workspace->id,
            'client_id' => $client->id,
            'status' => 'approved',
            'amount' => $approvedAmount,
        ]);

        // Unapproved money must not count — same rule as payments_by_month.
        Payment::factory()->create([
            'workspace_id' => $workspace->id,
            'client_id' => $client->id,
            'status' => 'pending',
            'amount' => '999999',
        ]);

        for ($i = 0; $i < $extraClients; $i++) {
            Client::factory()->create(['manager_id' => $manager->id]);
        }

        for ($i = 0; $i < $contracts; $i++) {
            Contract::factory()->withCreator($manager)->create(['workspace_id' => $workspace->id]);
        }

        return $manager;
    }

    private function managerStats(?User $actingAs = null): array
    {
        return $this->actingAs($actingAs ?? $this->admin)->getJson('/api/reports')
            ->assertOk()
            ->json('manager_stats');
    }

    public function test_reports_revenue_and_counts_per_manager(): void
    {
        $this->managerWithData($this->admin->id, 'Manager A', '1000', extraClients: 1, contracts: 2);

        $stats = $this->managerStats();

        $this->assertCount(1, $stats);
        $this->assertSame('Manager A', $stats[0]['name']);
        // assertEquals, not assertSame: json_encode() drops the trailing
        // .0 on a whole-number float (PHP's default, no
        // JSON_PRESERVE_ZERO_FRACTION), so 1000.0 round-trips through the
        // HTTP response as the JSON int 1000. Same reason
        // ReportsRevenueTest casts through floatval() instead of comparing
        // raw decoded JSON with assertSame.
        $this->assertEquals(1000.0, $stats[0]['revenue']);
        $this->assertSame(2, $stats[0]['clients']); // the funded client + the extra one
        $this->assertSame(2, $stats[0]['contracts']);
    }

    public function test_pending_payments_are_not_counted_as_manager_revenue(): void
    {
        $manager = $this->managerWithData($this->admin->id, 'Manager A', '1000');

        $stats = $this->managerStats();

        // managerWithData already adds a 999999 pending payment; if that
        // leaked in, revenue would be 1000999, not 1000.
        $this->assertEquals(1000.0, $stats[0]['revenue']);
    }

    public function test_managers_are_ranked_by_revenue_descending(): void
    {
        $this->managerWithData($this->admin->id, 'Low', '500');
        $this->managerWithData($this->admin->id, 'High', '5000');
        $this->managerWithData($this->admin->id, 'Mid', '2000');

        $stats = $this->managerStats();

        $this->assertSame(['High', 'Mid', 'Low'], array_column($stats, 'name'));
    }

    public function test_a_manager_with_no_approved_payments_reports_zero_not_a_guess(): void
    {
        $manager = User::factory()->create([
            'role' => User::ROLE_ACCOUNT_MANAGER,
            'super_admin_id' => $this->admin->id,
            'name' => 'No Revenue Yet',
        ]);
        Client::factory()->create(['manager_id' => $manager->id]);

        $stats = $this->managerStats();

        $this->assertEquals(0.0, $stats[0]['revenue']);
    }

    public function test_a_super_admin_only_sees_managers_they_created(): void
    {
        $otherAdmin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $this->managerWithData($this->admin->id, 'Mine', '1000');
        $this->managerWithData($otherAdmin->id, 'Not Mine', '9000');

        $stats = $this->managerStats();

        $this->assertSame(['Mine'], array_column($stats, 'name'));
    }

    public function test_an_account_manager_only_sees_their_own_row(): void
    {
        $manager = $this->managerWithData($this->admin->id, 'Self', '1000');
        $this->managerWithData($this->admin->id, 'Peer', '9000');

        $stats = $this->managerStats($manager);

        $this->assertSame(['Self'], array_column($stats, 'name'));
    }
}
