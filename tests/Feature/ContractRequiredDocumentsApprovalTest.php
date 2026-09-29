<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractRequiredDocument;
use App\Models\FileEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContractRequiredDocumentsApprovalTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private Client $client;
    private Workspace $workspace;
    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $this->client = Client::factory()->create([
            'manager_id' => $this->manager->id,
            'signature_data' => 'data:image/png;base64,sampleSignatureData',
        ]);
        $this->workspace = Workspace::factory()->create([
            'client_id' => $this->client->id,
            'manager_id' => $this->manager->id,
        ]);
        $this->contract = Contract::factory()->create([
            'workspace_id' => $this->workspace->id,
            'status' => 'sent',
        ]);
    }

    private function resetAuth(): void
    {
        $this->app->make('auth')->forgetGuards();
    }

    private function actingAsClient(): self
    {
        $token = $this->client->createToken('test')->plainTextToken;
        $this->resetAuth();
        $this->defaultHeaders = [];
        return $this->withHeaders(['Authorization' => 'Bearer ' . $token]);
    }

    public function test_cannot_approve_contract_when_required_document_has_no_file(): void
    {
        ContractRequiredDocument::create([
            'contract_id' => $this->contract->id,
            'name' => 'Commercial Registration',
            'is_required' => true,
            'sort_order' => 0,
        ]);

        $response = $this->actingAsClient()
            ->postJson("/api/contracts/{$this->contract->id}/client-action", [
                'action' => 'approved',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'code' => 'required_documents_missing',
                'missing_documents' => ['Commercial Registration'],
            ]);

        $this->assertEquals('sent', $this->contract->fresh()->status);
    }

    public function test_can_approve_contract_when_required_document_has_pending_or_approved_file(): void
    {
        $reqDoc = ContractRequiredDocument::create([
            'contract_id' => $this->contract->id,
            'name' => 'Commercial Registration',
            'is_required' => true,
            'sort_order' => 0,
        ]);

        FileEntry::create([
            'workspace_id' => $this->workspace->id,
            'contract_id' => $this->contract->id,
            'contract_required_document_id' => $reqDoc->id,
            'uploaded_by_type' => Client::class,
            'uploaded_by_id' => $this->client->id,
            'file_url' => '/storage/cr.pdf',
            'name' => 'cr.pdf',
            'status' => 'pending',
        ]);

        $response = $this->actingAsClient()
            ->postJson("/api/contracts/{$this->contract->id}/client-action", [
                'action' => 'approved',
            ]);

        $response->assertStatus(200);
        $this->assertEquals('client_approved', $this->contract->fresh()->status);
    }

    public function test_cannot_approve_contract_when_only_file_is_rejected(): void
    {
        $reqDoc = ContractRequiredDocument::create([
            'contract_id' => $this->contract->id,
            'name' => 'National ID',
            'is_required' => true,
            'sort_order' => 0,
        ]);

        FileEntry::create([
            'workspace_id' => $this->workspace->id,
            'contract_id' => $this->contract->id,
            'contract_required_document_id' => $reqDoc->id,
            'uploaded_by_type' => Client::class,
            'uploaded_by_id' => $this->client->id,
            'file_url' => '/storage/id.pdf',
            'name' => 'id.pdf',
            'status' => 'rejected',
            'rejection_reason' => 'Blurry copy',
        ]);

        $response = $this->actingAsClient()
            ->postJson("/api/contracts/{$this->contract->id}/client-action", [
                'action' => 'approved',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'code' => 'required_documents_missing',
                'missing_documents' => ['National ID'],
            ]);

        $this->assertEquals('sent', $this->contract->fresh()->status);
    }

    public function test_returns_only_missing_documents_when_multiple_are_required(): void
    {
        $doc1 = ContractRequiredDocument::create([
            'contract_id' => $this->contract->id,
            'name' => 'Commercial Registration',
            'is_required' => true,
            'sort_order' => 0,
        ]);
        $doc2 = ContractRequiredDocument::create([
            'contract_id' => $this->contract->id,
            'name' => 'Tax Card',
            'is_required' => true,
            'sort_order' => 1,
        ]);

        FileEntry::create([
            'workspace_id' => $this->workspace->id,
            'contract_id' => $this->contract->id,
            'contract_required_document_id' => $doc1->id,
            'uploaded_by_type' => Client::class,
            'uploaded_by_id' => $this->client->id,
            'file_url' => '/storage/cr.pdf',
            'name' => 'cr.pdf',
            'status' => 'pending',
        ]);

        $response = $this->actingAsClient()
            ->postJson("/api/contracts/{$this->contract->id}/client-action", [
                'action' => 'approved',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'code' => 'required_documents_missing',
                'missing_documents' => ['Tax Card'],
            ]);
    }

    public function test_can_approve_contract_without_any_required_documents(): void
    {
        $response = $this->actingAsClient()
            ->postJson("/api/contracts/{$this->contract->id}/client-action", [
                'action' => 'approved',
            ]);

        $response->assertStatus(200);
        $this->assertEquals('client_approved', $this->contract->fresh()->status);
    }

    public function test_can_request_edits_even_when_required_documents_are_missing(): void
    {
        ContractRequiredDocument::create([
            'contract_id' => $this->contract->id,
            'name' => 'Articles of Association',
            'is_required' => true,
            'sort_order' => 0,
        ]);

        $response = $this->actingAsClient()
            ->postJson("/api/contracts/{$this->contract->id}/client-action", [
                'action' => 'edit_requested',
                'reason' => 'Need change in payment terms',
            ]);

        $response->assertStatus(200);
        $this->assertEquals('edit_requested', $this->contract->fresh()->status);
    }

    public function test_signature_check_takes_precedence_over_documents_check(): void
    {
        $this->client->update(['signature_data' => null]);

        ContractRequiredDocument::create([
            'contract_id' => $this->contract->id,
            'name' => 'Commercial Registration',
            'is_required' => true,
            'sort_order' => 0,
        ]);

        $response = $this->actingAsClient()
            ->postJson("/api/contracts/{$this->contract->id}/client-action", [
                'action' => 'approved',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'code' => 'signature_required',
            ]);
    }
}
