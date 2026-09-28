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

class ContractUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function setupContract(string $status = 'draft', array $overrides = []): array
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $otherManager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $superAdmin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $client = Client::factory()->create(['manager_id' => $manager->id]);

        $workspace = Workspace::factory()->create([
            'client_id' => $client->id,
            'manager_id' => $manager->id,
            'status' => 'draft',
        ]);

        $contract = Contract::create(array_merge([
            'workspace_id' => $workspace->id,
            'title' => 'Original Title',
            'contract_type' => 'main',
            'value' => 5000,
            'currency' => 'SAR',
            'status' => $status,
            'pdf_url' => '/storage/contracts/contract-1-test.pdf',
        ], $overrides));

        return compact('manager', 'otherManager', 'superAdmin', 'client', 'workspace', 'contract');
    }

    public function test_manager_can_update_draft_contract(): void
    {
        $setup = $this->setupContract('draft');
        Sanctum::actingAs($setup['manager']);

        $response = $this->putJson("/api/contracts/{$setup['contract']->id}", [
            'title' => 'Updated Draft Title',
            'value' => 7500,
            'currency' => 'USD',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('Updated Draft Title', $setup['contract']->fresh()->title);
        $this->assertEquals(7500, (float) $setup['contract']->fresh()->value);
        $this->assertEquals('USD', $setup['contract']->fresh()->currency);
    }

    public function test_manager_can_update_edit_requested_contract(): void
    {
        $setup = $this->setupContract('edit_requested');
        Sanctum::actingAs($setup['manager']);

        $response = $this->putJson("/api/contracts/{$setup['contract']->id}", [
            'title' => 'Revised Contract Title',
            'value' => 6000,
        ]);

        $response->assertStatus(200);
        $this->assertEquals('Revised Contract Title', $setup['contract']->fresh()->title);
    }

    public function test_manager_cannot_update_sent_contract(): void
    {
        $setup = $this->setupContract('sent');
        Sanctum::actingAs($setup['manager']);

        $response = $this->putJson("/api/contracts/{$setup['contract']->id}", [
            'title' => 'Attempted Edit on Sent',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'code' => 'contract_not_editable',
            ]);

        $this->assertEquals('Original Title', $setup['contract']->fresh()->title);
    }

    public function test_manager_cannot_update_client_approved_contract(): void
    {
        $setup = $this->setupContract('client_approved');
        Sanctum::actingAs($setup['manager']);

        $response = $this->putJson("/api/contracts/{$setup['contract']->id}", [
            'title' => 'Attempted Edit on Approved',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'code' => 'contract_not_editable',
            ]);

        $this->assertEquals('Original Title', $setup['contract']->fresh()->title);
    }

    public function test_manager_cannot_update_completed_contract(): void
    {
        $setup = $this->setupContract('completed');
        Sanctum::actingAs($setup['manager']);

        $response = $this->putJson("/api/contracts/{$setup['contract']->id}", [
            'title' => 'Attempted Edit on Completed',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'code' => 'contract_not_editable',
            ]);

        $this->assertEquals('Original Title', $setup['contract']->fresh()->title);
    }

    public function test_another_manager_cannot_update_contract(): void
    {
        $setup = $this->setupContract('draft');
        Sanctum::actingAs($setup['otherManager']);

        $response = $this->putJson("/api/contracts/{$setup['contract']->id}", [
            'title' => 'Hijack Contract',
        ]);

        $response->assertStatus(403);
        $this->assertEquals('Original Title', $setup['contract']->fresh()->title);
    }

    public function test_updating_contract_clears_pdf_url_so_it_will_be_regenerated(): void
    {
        $setup = $this->setupContract('draft', ['pdf_url' => '/storage/contracts/old.pdf']);
        Sanctum::actingAs($setup['manager']);

        $response = $this->putJson("/api/contracts/{$setup['contract']->id}", [
            'title' => 'New Title',
        ]);

        $response->assertStatus(200);
        $this->assertNull($setup['contract']->fresh()->pdf_url);
    }

    public function test_syncing_required_documents_preserves_existing_docs_and_their_files(): void
    {
        $setup = $this->setupContract('draft');
        Sanctum::actingAs($setup['manager']);

        // Create 2 existing required documents
        $doc1 = $setup['contract']->requiredDocuments()->create([
            'name' => 'Commercial Register',
            'is_required' => true,
            'sort_order' => 0,
        ]);
        $doc2 = $setup['contract']->requiredDocuments()->create([
            'name' => 'Tax Certificate',
            'is_required' => true,
            'sort_order' => 1,
        ]);

        // Attach an uploaded file to doc1
        $clientFile = FileEntry::create([
            'workspace_id' => $setup['workspace']->id,
            'contract_id' => $setup['contract']->id,
            'contract_required_document_id' => $doc1->id,
            'uploaded_by_type' => Client::class,
            'uploaded_by_id' => $setup['client']->id,
            'name' => 'cr.pdf',
            'file_url' => 'files/cr.pdf',
            'size' => 1024,
            'type' => 'application/pdf',
            'status' => 'uploaded',
        ]);

        // Update with: doc1 kept, doc2 removed, doc3 added
        $response = $this->putJson("/api/contracts/{$setup['contract']->id}", [
            'title' => 'Title with Updated Docs',
            'required_documents' => [
                ['name' => 'Commercial Register'],
                ['name' => 'National ID'],
            ],
        ]);

        $response->assertStatus(200);

        // Doc1 should keep the exact same ID
        $this->assertDatabaseHas('contract_required_documents', [
            'id' => $doc1->id,
            'name' => 'Commercial Register',
        ]);

        // File should still be associated with doc1
        $this->assertEquals($doc1->id, $clientFile->fresh()->contract_required_document_id);

        // Doc2 should be deleted
        $this->assertDatabaseMissing('contract_required_documents', [
            'id' => $doc2->id,
        ]);

        // New doc should be created
        $this->assertDatabaseHas('contract_required_documents', [
            'contract_id' => $setup['contract']->id,
            'name' => 'National ID',
        ]);
    }

    public function test_clauses_can_be_updated_with_custom_fixed_optional(): void
    {
        $setup = $this->setupContract('draft');
        Sanctum::actingAs($setup['manager']);

        $response = $this->putJson("/api/contracts/{$setup['contract']->id}", [
            'title' => 'Contract with Clauses',
            'clauses' => [
                ['content' => 'Fixed Clause 1', 'type' => 'fixed'],
                ['content' => 'Optional Clause A', 'type' => 'optional'],
                ['content' => 'Custom Clause X', 'type' => 'custom'],
            ],
        ]);

        $response->assertStatus(200);
        $this->assertCount(3, $setup['contract']->fresh()->clauses);
        $this->assertDatabaseHas('contract_clauses', [
            'contract_id' => $setup['contract']->id,
            'content' => 'Fixed Clause 1',
            'type' => 'fixed',
        ]);
    }
}
