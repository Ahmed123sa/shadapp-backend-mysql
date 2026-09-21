<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Payment;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 21 Sept 2026 — AuditController::reports()'s payments_by_month had no
 * status filter, so pending and rejected payments were reported as
 * revenue. Both clients already claimed otherwise: the dashboard shows it
 * as "Monthly Revenue" and the mobile AM reports tab shows it under
 * "total accepted payments". The backend was the only layer that didn't
 * enforce it.
 */
class ReportsRevenueTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Client $client;
    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $this->client = Client::factory()->create(['manager_id' => $manager->id]);
        $this->workspace = Workspace::factory()->create([
            'client_id' => $this->client->id,
            'manager_id' => $manager->id,
        ]);
    }

    private function payment(string $status, string $amount): Payment
    {
        return Payment::factory()->create([
            'workspace_id' => $this->workspace->id,
            'client_id' => $this->client->id,
            'status' => $status,
            'amount' => $amount,
        ]);
    }

    private function revenueTotal(): float
    {
        $byMonth = $this->actingAs($this->admin)->getJson('/api/reports')
            ->assertOk()
            ->json('payments_by_month');

        return array_sum(array_map('floatval', $byMonth ?? []));
    }

    public function test_approved_payments_are_counted_as_revenue(): void
    {
        $this->payment('approved', '1000');

        $this->assertSame(1000.0, $this->revenueTotal());
    }

    public function test_pending_payments_are_not_counted_as_revenue(): void
    {
        $this->payment('approved', '1000');
        $this->payment('pending', '5000');

        // Money that has not been collected is not revenue.
        $this->assertSame(1000.0, $this->revenueTotal());
    }

    public function test_rejected_payments_are_not_counted_as_revenue(): void
    {
        $this->payment('approved', '1000');
        $this->payment('rejected', '7000');

        $this->assertSame(1000.0, $this->revenueTotal());
    }

    public function test_a_month_with_only_unapproved_payments_reports_nothing(): void
    {
        $this->payment('pending', '5000');
        $this->payment('rejected', '7000');

        $this->assertSame(0.0, $this->revenueTotal());
    }
}
