<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Payment;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 21 Sept 2026 — payments_by_month sums every currency into one number per
 * month, so the dashboard's Reports page labelled that number "EGP" no
 * matter what was actually paid (a payment made entirely in SAR still
 * displayed as EGP). payments_by_month_by_currency is the fix: same
 * approved-only query, grouped by currency too. payments_by_month itself is
 * left untouched — mobile still reads it — so this file also pins down that
 * it keeps behaving exactly as before.
 */
class PaymentsByMonthByCurrencyTest extends TestCase
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

    private function payment(string $status, string $amount, string $currency, ?string $createdAt = null): Payment
    {
        return Payment::factory()->create([
            'workspace_id' => $this->workspace->id,
            'client_id' => $this->client->id,
            'status' => $status,
            'amount' => $amount,
            'currency' => $currency,
            'created_at' => $createdAt ?? now(),
        ]);
    }

    private function reports(): array
    {
        return $this->actingAs($this->admin)->getJson('/api/reports')
            ->assertOk()
            ->json();
    }

    public function test_separates_revenue_by_currency_within_the_same_month(): void
    {
        $this->payment('approved', '5000', 'SAR');
        $this->payment('approved', '3000', 'USD');

        $byCurrency = $this->reports()['payments_by_month_by_currency'];
        $month = array_key_first($byCurrency);

        $this->assertEquals(5000.0, $byCurrency[$month]['SAR']);
        $this->assertEquals(3000.0, $byCurrency[$month]['USD']);
    }

    public function test_pending_and_rejected_payments_are_excluded(): void
    {
        $this->payment('approved', '1000', 'SAR');
        $this->payment('pending', '9999', 'SAR');
        $this->payment('rejected', '9999', 'USD');

        $byCurrency = $this->reports()['payments_by_month_by_currency'];
        $month = array_key_first($byCurrency);

        $this->assertEquals(1000.0, $byCurrency[$month]['SAR']);
        $this->assertArrayNotHasKey('USD', $byCurrency[$month]);
    }

    public function test_a_month_with_no_approved_payments_reports_no_currency_breakdown(): void
    {
        $this->payment('pending', '5000', 'SAR');

        $byCurrency = $this->reports()['payments_by_month_by_currency'];

        $this->assertSame([], $byCurrency);
    }

    // Regression: adding the new key must not change the old one's shape or
    // values — mobile's reports_tab and manager_detail_page still read
    // payments_by_month as a flat month => total map.
    public function test_the_old_payments_by_month_key_still_sums_across_currencies_unchanged(): void
    {
        $this->payment('approved', '5000', 'SAR');
        $this->payment('approved', '3000', 'USD');

        $data = $this->reports();
        $byMonth = $data['payments_by_month'];
        $month = array_key_first($byMonth);

        $this->assertEquals(8000.0, $byMonth[$month]);
    }
}
