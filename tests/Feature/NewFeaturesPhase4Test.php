<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Client;
use App\Models\Workspace;
use App\Models\Payment;
use App\Models\Contract;
use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewFeaturesPhase4Test extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private User $managerA;
    private User $managerB;
    private Client $clientA;
    private Client $clientB;
    private Workspace $workspaceA;
    private Workspace $workspaceB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $this->managerA = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER, 'super_admin_id' => $this->superAdmin->id]);
        $this->managerB = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER, 'super_admin_id' => $this->superAdmin->id]);

        $this->clientA = Client::factory()->create([
            'manager_id' => $this->managerA->id,
            'company_name' => 'Alpha Corporation',
        ]);
        $this->clientB = Client::factory()->create([
            'manager_id' => $this->managerB->id,
            'company_name' => 'Beta Logistics',
        ]);

        $this->workspaceA = Workspace::factory()->create(['client_id' => $this->clientA->id, 'manager_id' => $this->managerA->id]);
        $this->workspaceB = Workspace::factory()->create(['client_id' => $this->clientB->id, 'manager_id' => $this->managerB->id]);
    }

    public function test_all_payments_returns_stats_and_filters_by_status(): void
    {
        Payment::factory()->create([
            'workspace_id' => $this->workspaceA->id,
            'client_id' => $this->clientA->id,
            'amount' => 1000,
            'currency' => 'SAR',
            'status' => 'approved',
        ]);

        Payment::factory()->create([
            'workspace_id' => $this->workspaceA->id,
            'client_id' => $this->clientA->id,
            'amount' => 500,
            'currency' => 'USD',
            'status' => 'approved',
        ]);

        Payment::factory()->create([
            'workspace_id' => $this->workspaceB->id,
            'client_id' => $this->clientB->id,
            'amount' => 300,
            'currency' => 'SAR',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->superAdmin)->getJson('/api/all-payments?status=approved');
        $response->assertOk()
            ->assertJsonStructure(['payments', 'stats'])
            ->assertJsonCount(2, 'payments.data');

        $stats = $response->json('stats');
        $this->assertEquals(2, $stats['total_count']);
        $this->assertEquals(2, $stats['approved_count']);
        $this->assertEquals(1000, $stats['approved_total_sar']);
        $this->assertEquals(500, $stats['approved_total_usd']);
    }

    public function test_all_payments_filters_by_client_and_manager(): void
    {
        Payment::factory()->create([
            'workspace_id' => $this->workspaceA->id,
            'client_id' => $this->clientA->id,
            'amount' => 1000,
            'status' => 'approved',
        ]);

        Payment::factory()->create([
            'workspace_id' => $this->workspaceB->id,
            'client_id' => $this->clientB->id,
            'amount' => 2000,
            'status' => 'approved',
        ]);

        // Filter by clientA
        $resA = $this->actingAs($this->superAdmin)->getJson("/api/all-payments?client_id={$this->clientA->id}");
        $resA->assertOk()->assertJsonCount(1, 'payments.data');
        $this->assertEquals(1000, $resA->json('payments.data.0.amount'));

        // Filter by managerB
        $resB = $this->actingAs($this->superAdmin)->getJson("/api/all-payments?manager_id={$this->managerB->id}");
        $resB->assertOk()->assertJsonCount(1, 'payments.data');
        $this->assertEquals(2000, $resB->json('payments.data.0.amount'));
    }

    public function test_all_payments_filters_by_currency_and_date(): void
    {
        Payment::factory()->create([
            'workspace_id' => $this->workspaceA->id,
            'client_id' => $this->clientA->id,
            'amount' => 1500,
            'currency' => 'USD',
            'created_at' => now()->subDays(5),
        ]);

        Payment::factory()->create([
            'workspace_id' => $this->workspaceA->id,
            'client_id' => $this->clientA->id,
            'amount' => 1500,
            'currency' => 'SAR',
            'created_at' => now(),
        ]);

        $resUSD = $this->actingAs($this->superAdmin)->getJson('/api/all-payments?currency=USD');
        $resUSD->assertOk()->assertJsonCount(1, 'payments.data');
        $this->assertEquals('USD', $resUSD->json('payments.data.0.currency'));

        $today = now()->format('Y-m-d');
        $resDate = $this->actingAs($this->superAdmin)->getJson("/api/all-payments?date_from={$today}");
        $resDate->assertOk()->assertJsonCount(1, 'payments.data');
        $this->assertEquals('SAR', $resDate->json('payments.data.0.currency'));
    }

    public function test_super_admin_can_update_show_contract_dates_setting(): void
    {
        $response = $this->actingAs($this->superAdmin)->putJson('/api/settings', [
            'key' => 'show_contract_dates',
            'value' => '0',
        ]);
        $response->assertOk();

        $this->assertEquals('0', SystemSetting::getValue('show_contract_dates'));

        $getRes = $this->actingAs($this->superAdmin)->getJson('/api/settings');
        $getRes->assertOk();
        $this->assertEquals('0', $getRes->json('settings.show_contract_dates.value'));
    }

    public function test_account_manager_cannot_update_settings(): void
    {
        $response = $this->actingAs($this->managerA)->putJson('/api/settings', [
            'key' => 'show_contract_dates',
            'value' => '0',
        ]);
        $response->assertForbidden();
    }
}
