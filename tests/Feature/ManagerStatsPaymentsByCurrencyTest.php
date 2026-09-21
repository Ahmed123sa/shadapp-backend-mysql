<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Payment;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 21 Sept 2026 — AccountManagerController::stats()'s payments_by_month sums
 * every currency into one number with no currency attached, same
 * currency-mixing issue as AuditController::reports()'s payments_by_month
 * (just a different endpoint — this one backs manager_detail_page.dart's
 * income card and monthly-income chart on mobile, not the dashboard).
 * payments_by_month_by_currency is the same fix, scoped to one manager.
 */
class ManagerStatsPaymentsByCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $manager;
    private Client $client;
    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $this->manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $this->client = Client::factory()->create(['manager_id' => $this->manager->id]);
        $this->workspace = Workspace::factory()->create([
            'client_id' => $this->client->id,
            'manager_id' => $this->manager->id,
        ]);
    }

    private function payment(string $status, string $amount, string $currency): Payment
    {
        return Payment::factory()->create([
            'workspace_id' => $this->workspace->id,
            'client_id' => $this->client->id,
            'status' => $status,
            'amount' => $amount,
            'currency' => $currency,
        ]);
    }

    private function stats(): array
    {
        return $this->actingAs($this->admin)
            ->getJson("/api/account-managers/{$this->manager->id}/stats")
            ->assertOk()
            ->json();
    }

    public function test_separates_revenue_by_currency_within_the_same_month(): void
    {
        $this->payment('approved', '5000', 'SAR');
        $this->payment('approved', '3000', 'USD');

        $byCurrency = $this->stats()['payments_by_month_by_currency'];
        $month = array_key_first($byCurrency);

        $this->assertEquals(5000.0, $byCurrency[$month]['SAR']);
        $this->assertEquals(3000.0, $byCurrency[$month]['USD']);
    }

    public function test_pending_and_rejected_payments_are_excluded(): void
    {
        $this->payment('approved', '1000', 'SAR');
        $this->payment('pending', '9999', 'SAR');
        $this->payment('rejected', '9999', 'USD');

        $byCurrency = $this->stats()['payments_by_month_by_currency'];
        $month = array_key_first($byCurrency);

        $this->assertEquals(1000.0, $byCurrency[$month]['SAR']);
        $this->assertArrayNotHasKey('USD', $byCurrency[$month]);
    }

    // Regression: the new key must not change payments_by_month's own shape
    // or values — mobile's manager_detail_page still reads it as a flat
    // month => total map.
    public function test_the_old_payments_by_month_key_still_sums_across_currencies_unchanged(): void
    {
        $this->payment('approved', '5000', 'SAR');
        $this->payment('approved', '3000', 'USD');

        $data = $this->stats();
        $byMonth = $data['payments_by_month'];
        $month = array_key_first($byMonth);

        $this->assertEquals(8000.0, $byMonth[$month]);
    }

    public function test_a_manager_with_no_approved_payments_reports_an_empty_breakdown(): void
    {
        $this->payment('pending', '5000', 'SAR');

        $byCurrency = $this->stats()['payments_by_month_by_currency'];

        $this->assertSame([], $byCurrency);
    }
}
