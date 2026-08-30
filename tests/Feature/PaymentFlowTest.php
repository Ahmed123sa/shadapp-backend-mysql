<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Workspace;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $managerA;
    private User $superAdmin;
    private Client $clientA;
    private Client $clientB;
    private Workspace $workspaceA;
    private Workspace $workspaceB;

    private function resetAuth(): void
    {
        $this->app->make('auth')->forgetGuards();
    }

    private function actingAsClient(Client $client): self
    {
        $this->resetAuth();
        return $this->actingAs($client, 'client');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $this->managerA = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER, 'super_admin_id' => $this->superAdmin->id]);
        $this->managerA = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $this->clientA = Client::factory()->create(['manager_id' => $this->managerA->id]);
        $this->clientB = Client::factory()->create(['manager_id' => $this->managerA->id]);
        $this->workspaceA = Workspace::factory()->create(['client_id' => $this->clientA->id, 'manager_id' => $this->managerA->id]);
        $this->workspaceB = Workspace::factory()->create(['client_id' => $this->clientB->id, 'manager_id' => $this->managerA->id]);
    }

    public function test_manager_can_approve_own_payment(): void
    {
        $payment = Payment::factory()->create([
            'workspace_id' => $this->workspaceA->id,
            'client_id' => $this->clientA->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->managerA)->postJson("/api/payments/{$payment->id}/review", [
            'action' => 'approved',
        ]);
        $response->assertOk();
        $this->assertEquals('approved', $payment->fresh()->status);
    }

    public function test_super_admin_can_approve_any_payment(): void
    {
        $payment = Payment::factory()->create([
            'workspace_id' => $this->workspaceB->id,
            'client_id' => $this->clientB->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->superAdmin)->postJson("/api/payments/{$payment->id}/review", [
            'action' => 'approved',
        ]);
        $response->assertOk();
        $this->assertEquals('approved', $payment->fresh()->status);
    }

    public function test_manager_only_sees_own_pending_payments(): void
    {
        $otherManager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $otherClient = Client::factory()->create(['manager_id' => $otherManager->id]);
        $otherWorkspace = Workspace::factory()->create(['client_id' => $otherClient->id, 'manager_id' => $otherManager->id]);

        Payment::factory()->create(['workspace_id' => $this->workspaceA->id, 'client_id' => $this->clientA->id, 'status' => 'pending']);
        Payment::factory()->create(['workspace_id' => $otherWorkspace->id, 'client_id' => $otherClient->id, 'status' => 'pending']);

        $response = $this->actingAs($this->managerA)->getJson('/api/payments/pending');
        $response->assertOk();
        $this->assertCount(1, $response->json('payments.data'));
    }

    private function payableContract(string $title, int $value, ?\Carbon\Carbon $createdAt = null): Contract
    {
        return Contract::factory()->create([
            'workspace_id' => $this->workspaceA->id,
            'created_by' => $this->managerA->id,
            'status' => 'company_approved',
            'title' => $title,
            'value' => $value,
            'currency' => 'USD',
            'created_at' => $createdAt ?? now(),
        ]);
    }

    public function test_payment_store_links_to_explicit_older_contract_not_latest(): void
    {
        $old = $this->payableContract('Old contract', 5000, now()->subDay());
        $latest = $this->payableContract('Latest contract', 9000, now());

        $response = $this->actingAsClient($this->clientA)
            ->postJson("/api/workspaces/{$this->workspaceA->id}/payments", [
                'amount' => 2000,
                'method_type' => 'bank_transfer',
                'contract_id' => $old->id,
            ]);

        $response->assertStatus(201);
        $this->assertEquals($old->id, $response->json('payment.contract_id'));
    }

    public function test_payment_store_falls_back_to_latest_contract_without_contract_id(): void
    {
        $old = $this->payableContract('Old contract', 5000, now()->subDay());
        $latest = $this->payableContract('Latest contract', 9000, now());

        $response = $this->actingAsClient($this->clientA)
            ->postJson("/api/workspaces/{$this->workspaceA->id}/payments", [
                'amount' => 2000,
                'method_type' => 'bank_transfer',
            ]);

        $response->assertStatus(201);
        $this->assertEquals($latest->id, $response->json('payment.contract_id'));
    }

    public function test_payment_store_rejects_contract_from_another_workspace(): void
    {
        $foreign = Contract::factory()->create([
            'workspace_id' => $this->workspaceB->id,
            'created_by' => $this->managerA->id,
            'status' => 'company_approved',
        ]);

        $response = $this->actingAsClient($this->clientA)
            ->postJson("/api/workspaces/{$this->workspaceA->id}/payments", [
                'amount' => 2000,
                'method_type' => 'bank_transfer',
                'contract_id' => $foreign->id,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('contract_id');
    }

    public function test_payment_store_blocks_duplicate_pending_for_selected_contract(): void
    {
        $contractA = $this->payableContract('Contract A', 5000);
        $contractB = $this->payableContract('Contract B', 9000);
        Payment::factory()->create([
            'workspace_id' => $this->workspaceA->id,
            'client_id' => $this->clientA->id,
            'contract_id' => $contractA->id,
            'status' => 'pending',
        ]);

        $dup = $this->actingAsClient($this->clientA)
            ->postJson("/api/workspaces/{$this->workspaceA->id}/payments", [
                'amount' => 100,
                'method_type' => 'bank_transfer',
                'contract_id' => $contractA->id,
            ]);
        $dup->assertStatus(422);

        $ok = $this->actingAsClient($this->clientA)
            ->postJson("/api/workspaces/{$this->workspaceA->id}/payments", [
                'amount' => 100,
                'method_type' => 'bank_transfer',
                'contract_id' => $contractB->id,
            ]);
        $ok->assertStatus(201);
        $this->assertEquals($contractB->id, $ok->json('payment.contract_id'));
    }
}
