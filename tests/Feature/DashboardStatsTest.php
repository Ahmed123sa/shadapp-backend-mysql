<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Payment;
use App\Models\User;
use App\Models\Workspace;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 24 Sept 2026 — GET /dashboard/stats (server-side-stats-plan.md). Web and
 * mobile used to each compute these cards themselves from a paginated list
 * (capped at 30-100 rows), so the numbers were wrong once real data grew
 * past that cap and disagreed with each other. This endpoint computes every
 * card as a full COUNT/SUM over the whole table, scoped the same way
 * /reports already scopes an account manager to their own clients.
 */
class DashboardStatsTest extends TestCase
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

    public function test_an_account_manager_sees_only_their_own_clients_numbers(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $other = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);

        $this->clientFor($manager);
        $this->clientFor($manager);
        $this->clientFor($other);

        $response = $this->actingAs($manager)->getJson('/api/dashboard/stats')->assertOk();

        $this->assertSame(2, $response->json('clients.total'));
    }

    public function test_a_super_admin_sees_every_clients_numbers(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $managerA = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $managerB = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);

        $this->clientFor($managerA);
        $this->clientFor($managerB);

        $response = $this->actingAs($admin)->getJson('/api/dashboard/stats')->assertOk();

        $this->assertSame(2, $response->json('clients.total'));
    }

    // The bug this whole endpoint exists to fix: a client-side count capped
    // at the first page (30 rows) silently under-counted once real data grew
    // past it. A server-side COUNT has no such cap.
    public function test_active_contracts_stays_correct_past_thirty_records(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $client = $this->clientFor($manager);
        $workspace = $this->workspaceFor($client, $manager);

        Contract::factory()->count(35)->create(['workspace_id' => $workspace->id, 'status' => 'company_approved']);
        Contract::factory()->count(5)->create(['workspace_id' => $workspace->id, 'status' => 'draft']);

        $response = $this->actingAs($manager)->getJson('/api/dashboard/stats')->assertOk();

        $this->assertSame(35, $response->json('contracts.active'));
    }

    public function test_awaiting_client_contracts_are_sent_or_client_approved(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $client = $this->clientFor($manager);
        $workspace = $this->workspaceFor($client, $manager);

        Contract::factory()->create(['workspace_id' => $workspace->id, 'status' => 'sent']);
        Contract::factory()->create(['workspace_id' => $workspace->id, 'status' => 'client_approved']);
        Contract::factory()->create(['workspace_id' => $workspace->id, 'status' => 'draft']);
        Contract::factory()->create(['workspace_id' => $workspace->id, 'status' => 'completed']);

        $response = $this->actingAs($manager)->getJson('/api/dashboard/stats')->assertOk();

        $this->assertSame(2, $response->json('contracts.awaiting_client'));
    }

    public function test_archived_clients_are_not_counted(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $this->clientFor($manager);
        Client::factory()->create(['manager_id' => $manager->id, 'status' => 'archived']);

        $response = $this->actingAs($manager)->getJson('/api/dashboard/stats')->assertOk();

        $this->assertSame(1, $response->json('clients.total'));
    }

    public function test_revenue_this_month_only_counts_approved_payments_grouped_by_currency(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $client = $this->clientFor($manager);
        $workspace = $this->workspaceFor($client, $manager);

        Payment::factory()->create([
            'workspace_id' => $workspace->id, 'client_id' => $client->id,
            'status' => 'approved', 'currency' => 'SAR', 'amount' => 5000,
        ]);
        Payment::factory()->create([
            'workspace_id' => $workspace->id, 'client_id' => $client->id,
            'status' => 'approved', 'currency' => 'USD', 'amount' => 1200,
        ]);
        // Neither of these should count: one is pending, one is rejected.
        Payment::factory()->create([
            'workspace_id' => $workspace->id, 'client_id' => $client->id,
            'status' => 'pending', 'currency' => 'SAR', 'amount' => 9999,
        ]);
        Payment::factory()->create([
            'workspace_id' => $workspace->id, 'client_id' => $client->id,
            'status' => 'rejected', 'currency' => 'SAR', 'amount' => 9999,
        ]);

        $response = $this->actingAs($manager)->getJson('/api/dashboard/stats')->assertOk();

        // assertEquals, not assertSame: json_encode drops the trailing .0 on
        // a whole-number float, so this decodes as an int, not a float — the
        // same reason PaymentsByMonthByCurrencyTest compares this way too.
        $this->assertEquals(5000.0, $response->json('revenue_this_month.SAR'));
        $this->assertEquals(1200.0, $response->json('revenue_this_month.USD'));
    }

    // The exact case the plan calls out: 23:30 UTC on the last day of a
    // month is already past midnight the next day in Cairo (UTC+3 in the
    // Egyptian summer-time window). A UTC-calendar-month filter would file
    // this payment under August; the Cairo-aware one must file it under
    // September, alongside "now" — the same class of bug already fixed for
    // the calendar and meetings elsewhere in this project.
    public function test_month_boundary_uses_egypt_time_not_utc(): void
    {
        config(['app.display_timezone' => 'Africa/Cairo']);
        // Comfortably inside September in Cairo time.
        Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00', 'Africa/Cairo'));

        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $client = $this->clientFor($manager);
        $workspace = $this->workspaceFor($client, $manager);

        Payment::factory()->create([
            'workspace_id' => $workspace->id, 'client_id' => $client->id,
            'status' => 'approved', 'currency' => 'SAR', 'amount' => 4242,
            'created_at' => Carbon::parse('2026-08-31 23:30:00', 'UTC'),
        ]);

        $response = $this->actingAs($manager)->getJson('/api/dashboard/stats')->assertOk();

        $this->assertSame('2026-09', $response->json('period.month'));
        $this->assertSame('Africa/Cairo', $response->json('period.timezone'));
        $this->assertEquals(4242.0, $response->json('revenue_this_month.SAR'));

        Carbon::setTestNow();
    }

    // The "approvals" card must never drift from the badge that opens the
    // same screen — that's the exact three-way mismatch this whole plan
    // was written to close (see server-side-stats-plan.md §2.2).
    public function test_approvals_total_matches_the_badge_count_for_the_same_user(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $client = $this->clientFor($manager);
        $workspace = $this->workspaceFor($client, $manager);

        Contract::factory()->create(['workspace_id' => $workspace->id, 'status' => 'sent']);
        Approval::factory()->create(['workspace_id' => $workspace->id, 'requested_by' => $manager->id]);
        Payment::factory()->create(['workspace_id' => $workspace->id, 'client_id' => $client->id, 'status' => 'pending']);

        $stats = $this->actingAs($manager)->getJson('/api/dashboard/stats')->assertOk();
        $badges = $this->actingAs($manager)->getJson('/api/badge-counts')->assertOk();

        $this->assertSame(3, $stats->json('approvals.total'));
        $this->assertSame($badges->json('approvals'), $stats->json('approvals.total'));
    }

    // A client/sub-user token being refused (403) and an unauthenticated
    // request being refused (401) are covered by StaffOnlyRouteTest's
    // staffOnlyRoutes() data provider, which this route was added to — that
    // test uses a real bearer token rather than actingAs(), which matters
    // for a staff.only route (see that file's class docblock).
}
