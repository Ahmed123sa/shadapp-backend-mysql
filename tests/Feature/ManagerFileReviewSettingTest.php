<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\FileEntry;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagerFileReviewSettingTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private User $manager;
    private User $otherManager;
    private Client $client;
    private Workspace $workspace;
    private FileEntry $fileEntry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $this->manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $this->otherManager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $this->client = Client::factory()->create(['manager_id' => $this->manager->id]);
        $this->workspace = Workspace::factory()->create([
            'client_id' => $this->client->id,
            'manager_id' => $this->manager->id,
        ]);
        $this->fileEntry = FileEntry::create([
            'workspace_id' => $this->workspace->id,
            'uploaded_by_type' => Client::class,
            'uploaded_by_id' => $this->client->id,
            'file_url' => '/storage/doc.pdf',
            'name' => 'doc.pdf',
            'status' => 'pending',
        ]);
    }

    public function test_manager_cannot_review_files_when_setting_is_disabled(): void
    {
        SystemSetting::setValue('managers_can_review_files', '0');

        $response = $this->actingAs($this->manager)
            ->postJson("/api/files/{$this->fileEntry->id}/review", [
                'action' => 'approved',
            ]);

        $response->assertStatus(403);
        $this->assertEquals('pending', $this->fileEntry->fresh()->status);
    }

    public function test_manager_can_review_files_in_own_workspace_when_setting_is_enabled(): void
    {
        SystemSetting::setValue('managers_can_review_files', '1');

        $response = $this->actingAs($this->manager)
            ->postJson("/api/files/{$this->fileEntry->id}/review", [
                'action' => 'approved',
            ]);

        $response->assertOk();
        $this->assertEquals('approved', $this->fileEntry->fresh()->status);
        $this->assertEquals($this->manager->id, $this->fileEntry->fresh()->reviewed_by);
    }

    public function test_manager_cannot_review_files_in_another_managers_workspace(): void
    {
        SystemSetting::setValue('managers_can_review_files', '1');

        $response = $this->actingAs($this->otherManager)
            ->postJson("/api/files/{$this->fileEntry->id}/review", [
                'action' => 'approved',
            ]);

        $response->assertStatus(403);
        $this->assertEquals('pending', $this->fileEntry->fresh()->status);
    }

    public function test_super_admin_can_review_files_regardless_of_setting(): void
    {
        SystemSetting::setValue('managers_can_review_files', '0');

        $response = $this->actingAs($this->superAdmin)
            ->postJson("/api/files/{$this->fileEntry->id}/review", [
                'action' => 'approved',
            ]);

        $response->assertOk();
        $this->assertEquals('approved', $this->fileEntry->fresh()->status);
    }

    public function test_super_admin_can_update_managers_can_review_files_setting(): void
    {
        $response = $this->actingAs($this->superAdmin)
            ->putJson('/api/settings', [
                'key' => 'managers_can_review_files',
                'value' => '1',
            ]);

        $response->assertOk();
        $this->assertEquals('1', SystemSetting::getValue('managers_can_review_files'));
    }

    public function test_manager_cannot_update_settings(): void
    {
        $response = $this->actingAs($this->manager)
            ->putJson('/api/settings', [
                'key' => 'managers_can_review_files',
                'value' => '1',
            ]);

        $response->assertStatus(403);
    }

    private function contractDocumentFor(User $manager): FileEntry
    {
        $contract = \App\Models\Contract::factory()->create(['workspace_id' => $this->workspace->id]);

        return FileEntry::create([
            'workspace_id' => $this->workspace->id,
            'contract_id' => $contract->id,
            'uploaded_by_type' => Client::class,
            'uploaded_by_id' => $this->client->id,
            'file_url' => '/storage/contract-doc.pdf',
            'name' => 'contract-doc.pdf',
            'status' => 'pending',
        ]);
    }

    public function test_manager_allowed_to_approve_contracts_can_review_that_contracts_documents_even_with_setting_off(): void
    {
        SystemSetting::setValue('managers_can_review_files', '0');
        $this->manager->update(['can_approve_contracts' => true]);
        $doc = $this->contractDocumentFor($this->manager);

        $this->actingAs($this->manager->fresh())
            ->postJson("/api/files/{$doc->id}/review", ['action' => 'approved'])
            ->assertOk();

        $this->assertEquals('approved', $doc->fresh()->status);
    }

    public function test_contract_approval_permission_does_not_open_ordinary_files(): void
    {
        SystemSetting::setValue('managers_can_review_files', '0');
        $this->manager->update(['can_approve_contracts' => true]);

        $this->actingAs($this->manager->fresh())
            ->postJson("/api/files/{$this->fileEntry->id}/review", ['action' => 'approved'])
            ->assertStatus(403);
    }

    public function test_manager_without_contract_approval_cannot_review_contract_documents_when_setting_off(): void
    {
        SystemSetting::setValue('managers_can_review_files', '0');
        $doc = $this->contractDocumentFor($this->manager);

        $this->actingAs($this->manager)
            ->postJson("/api/files/{$doc->id}/review", ['action' => 'approved'])
            ->assertStatus(403);
    }

    public function test_approving_manager_cannot_review_another_managers_contract_documents(): void
    {
        SystemSetting::setValue('managers_can_review_files', '0');
        $this->otherManager->update(['can_approve_contracts' => true]);
        $doc = $this->contractDocumentFor($this->manager);

        $this->actingAs($this->otherManager->fresh())
            ->postJson("/api/files/{$doc->id}/review", ['action' => 'approved'])
            ->assertStatus(403);
    }
}
