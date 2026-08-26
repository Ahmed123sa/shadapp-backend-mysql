<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Client;
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
}
