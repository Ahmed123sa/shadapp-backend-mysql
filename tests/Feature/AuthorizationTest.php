<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Models\Workspace;
use App\Models\Contract;
use App\Models\Payment;
use App\Models\Approval;
use App\Models\Meeting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationTest extends TestCase
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

        $this->clientA = Client::factory()->create(['manager_id' => $this->managerA->id]);
        $this->clientB = Client::factory()->create(['manager_id' => $this->managerB->id]);

        $this->workspaceA = Workspace::factory()->create(['client_id' => $this->clientA->id, 'manager_id' => $this->managerA->id]);
        $this->workspaceB = Workspace::factory()->create(['client_id' => $this->clientB->id, 'manager_id' => $this->managerB->id]);
    }

    // ─── Client Authorization ─────────────────────────────────

    public function test_super_admin_can_view_all_clients(): void
    {
        $response = $this->actingAs($this->superAdmin)->getJson('/api/clients');

        $response->assertOk();
        $this->assertCount(2, $response->json('clients.data'));
    }

    public function test_manager_can_only_view_own_clients(): void
    {
        $response = $this->actingAs($this->managerA)->getJson('/api/clients');

        $response->assertOk();
        $data = $response->json('clients.data');
        $this->assertCount(1, $data);
        $this->assertEquals($this->clientA->id, $data[0]['id']);
    }

    public function test_manager_cannot_view_another_managers_client(): void
    {
        $response = $this->actingAs($this->managerA)->getJson("/api/clients/{$this->clientB->id}");

        $response->assertForbidden();
    }

    public function test_super_admin_can_view_any_client(): void
    {
        $response = $this->actingAs($this->superAdmin)->getJson("/api/clients/{$this->clientB->id}");

        $response->assertOk();
    }

    public function test_manager_cannot_update_another_managers_client(): void
    {
        $response = $this->actingAs($this->managerA)->putJson("/api/clients/{$this->clientB->id}", [
            'company_name' => 'Hacked Name',
        ]);

        $response->assertForbidden();
    }

    public function test_manager_cannot_delete_another_managers_client(): void
    {
        $response = $this->actingAs($this->managerA)->deleteJson("/api/clients/{$this->clientB->id}");

        $response->assertForbidden();
    }

    // ─── Contract Authorization ────────────────────────────────

    public function test_manager_cannot_view_another_managers_contract(): void
    {
        $contract = Contract::factory()
            ->withCreator($this->managerB)
            ->create(['workspace_id' => $this->workspaceB->id]);

        $response = $this->actingAs($this->managerA)->getJson("/api/contracts/{$contract->id}");

        $response->assertForbidden();
    }

    public function test_manager_cannot_update_another_managers_contract(): void
    {
        $contract = Contract::factory()
            ->withCreator($this->managerB)
            ->create(['workspace_id' => $this->workspaceB->id]);

        $response = $this->actingAs($this->managerA)->putJson("/api/contracts/{$contract->id}", [
            'title' => 'Hacked Contract',
        ]);

        $response->assertForbidden();
    }

    public function test_manager_cannot_send_another_managers_contract(): void
    {
        $contract = Contract::factory()
            ->withCreator($this->managerB)
            ->create(['workspace_id' => $this->workspaceB->id, 'status' => 'draft']);

        $response = $this->actingAs($this->managerA)->postJson("/api/contracts/{$contract->id}/send");

        $response->assertForbidden();
    }

    // ─── Payment Authorization ─────────────────────────────────

    public function test_manager_cannot_review_another_managers_payment(): void
    {
        $payment = Payment::factory()->create([
            'workspace_id' => $this->workspaceB->id,
            'client_id' => $this->clientB->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->managerA)->postJson("/api/payments/{$payment->id}/review", [
            'action' => 'approved',
        ]);

        $response->assertForbidden();
    }

    // ─── Approval Authorization ────────────────────────────────

    public function test_manager_cannot_view_another_managers_approval(): void
    {
        $approval = Approval::factory()->create([
            'workspace_id' => $this->workspaceB->id,
            'requested_by' => $this->managerB->id,
        ]);

        $response = $this->actingAs($this->managerA)->getJson("/api/approvals/{$approval->id}");

        $response->assertForbidden();
    }

    // ─── Meeting Authorization ─────────────────────────────────

    public function test_manager_cannot_create_meeting_for_another_managers_workspace(): void
    {
        $response = $this->actingAs($this->managerA)->postJson("/api/workspaces/{$this->workspaceB->id}/meetings", [
            'title' => 'Test Meeting',
            'scheduled_at' => now()->addDay()->toDateString(),
        ]);

        $response->assertForbidden();
    }

    // ─── Unauthenticated Access ────────────────────────────────

    public function test_unauthenticated_user_gets_401_on_all_endpoints(): void
    {
        $response = $this->getJson('/api/clients');
        $response->assertUnauthorized();

        $response = $this->getJson('/api/account-managers');
        $response->assertUnauthorized();

        $response = $this->getJson('/api/audit-logs');
        $response->assertUnauthorized();

        $response = $this->getJson('/api/reports');
        $response->assertUnauthorized();
    }
}
