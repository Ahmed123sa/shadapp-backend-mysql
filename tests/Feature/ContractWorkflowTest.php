<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Client;
use App\Models\Workspace;
use App\Models\Contract;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContractWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private Client $client;
    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $this->client = Client::factory()->create(['manager_id' => $this->manager->id]);
        $this->workspace = Workspace::factory()->create([
            'client_id' => $this->client->id,
            'manager_id' => $this->manager->id,
        ]);
    }

    private function resetAuth(): void
    {
        $this->app->make('auth')->forgetGuards();
    }

    public function test_contract_goes_from_draft_to_sent(): void
    {
        $response = $this->actingAs($this->manager)->postJson("/api/workspaces/{$this->workspace->id}/contracts", [
            'title' => 'Test Contract',
            'value' => 10000,
        ]);
        $response->assertCreated();
        $contractId = $response->json('contract.id');

        $this->assertEquals('draft', Contract::find($contractId)->status);

        $response = $this->actingAs($this->manager)->postJson("/api/contracts/{$contractId}/send");
        $response->assertOk();
        $this->assertEquals('sent', Contract::find($contractId)->status);
    }

    public function test_client_can_approve_contract(): void
    {
        $response = $this->actingAs($this->manager)->postJson("/api/workspaces/{$this->workspace->id}/contracts", [
            'title' => 'Approvable Contract',
        ]);
        $contractId = $response->json('contract.id');
        $this->actingAs($this->manager)->postJson("/api/contracts/{$contractId}/send");

        $token = $this->client->createToken('test')->plainTextToken;
        $this->resetAuth();
        $this->defaultHeaders = [];
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson("/api/contracts/{$contractId}/client-action", ['action' => 'approved']);

        $response->assertOk();
        $this->assertEquals('client_approved', Contract::find($contractId)->status);
        $this->assertNotNull(Contract::find($contractId)->client_signed_at);
    }

    public function test_client_can_request_edits(): void
    {
        $response = $this->actingAs($this->manager)->postJson("/api/workspaces/{$this->workspace->id}/contracts", [
            'title' => 'Editable Contract',
        ]);
        $contractId = $response->json('contract.id');
        $this->actingAs($this->manager)->postJson("/api/contracts/{$contractId}/send");

        $token = $this->client->createToken('test')->plainTextToken;
        $this->resetAuth();
        $this->defaultHeaders = [];
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson("/api/contracts/{$contractId}/client-action", ['action' => 'edit_requested']);

        $response->assertOk();
        $this->assertEquals('edit_requested', Contract::find($contractId)->status);
    }

    public function test_audit_log_created_on_send(): void
    {
        $response = $this->actingAs($this->manager)->postJson("/api/workspaces/{$this->workspace->id}/contracts", [
            'title' => 'Audit Contract',
        ]);
        $contractId = $response->json('contract.id');
        $this->actingAs($this->manager)->postJson("/api/contracts/{$contractId}/send");

        $this->assertDatabaseHas('audit_logs', [
            'auditable_id' => $contractId,
            'action' => 'contract.sent',
        ]);
    }

    public function test_company_can_approve_contract(): void
    {
        $response = $this->actingAs($this->manager)->postJson("/api/workspaces/{$this->workspace->id}/contracts", [
            'title' => 'Company Approve',
        ]);
        $contractId = $response->json('contract.id');
        $this->actingAs($this->manager)->postJson("/api/contracts/{$contractId}/send");

        $token = $this->client->createToken('test')->plainTextToken;
        $this->resetAuth();
        $this->defaultHeaders = [];
        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson("/api/contracts/{$contractId}/client-action", ['action' => 'approved']);

        $response = $this->actingAs($this->manager)->postJson("/api/contracts/{$contractId}/company-approve");
        $response->assertOk();
        $this->assertEquals('company_approved', Contract::find($contractId)->status);
    }
}
