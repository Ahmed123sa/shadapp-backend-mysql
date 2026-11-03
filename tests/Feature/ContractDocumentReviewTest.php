<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractRequiredDocument;
use App\Models\FileEntry;
use App\Models\SubUser;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\ContractDocumentUploadedNotification;
use App\Notifications\FileRejectedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The company can only approve a contract once every required document has an
 * approved file; the client is told when a file is rejected; the client's own
 * manager and the super admins are told when the client uploads one.
 */
class ContractDocumentReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private User $manager;
    private User $otherManager;
    private Client $client;
    private Workspace $workspace;
    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'signature_data' => 'توقيع الشركة',
        ]);
        $this->manager = User::factory()->create([
            'role' => User::ROLE_ACCOUNT_MANAGER,
            'super_admin_id' => $this->superAdmin->id,
            'is_active' => true,
        ]);
        $this->otherManager = User::factory()->create([
            'role' => User::ROLE_ACCOUNT_MANAGER,
            'super_admin_id' => $this->superAdmin->id,
            'is_active' => true,
        ]);
        $this->client = Client::factory()->create(['manager_id' => $this->manager->id]);
        $this->workspace = Workspace::factory()->create([
            'client_id' => $this->client->id,
            'manager_id' => $this->manager->id,
            'status' => 'inactive',
        ]);
        $this->contract = Contract::factory()->create([
            'workspace_id' => $this->workspace->id,
            'status' => 'client_approved',
            'created_by' => $this->manager->id,
            'title' => 'Remax SEO',
        ]);
    }

    private function requiredDoc(string $name, bool $required = true): ContractRequiredDocument
    {
        return ContractRequiredDocument::create([
            'contract_id' => $this->contract->id,
            'name' => $name,
            'is_required' => $required,
            'sort_order' => 0,
        ]);
    }

    private function fileFor(ContractRequiredDocument $doc, string $status, ?string $reason = null): FileEntry
    {
        return FileEntry::create([
            'workspace_id' => $this->workspace->id,
            'contract_id' => $this->contract->id,
            'contract_required_document_id' => $doc->id,
            'uploaded_by_type' => Client::class,
            'uploaded_by_id' => $this->client->id,
            'file_url' => '/storage/doc.pdf',
            'name' => 'doc.pdf',
            'status' => $status,
            'rejection_reason' => $reason,
        ]);
    }

    private function asClient(): self
    {
        $token = $this->client->createToken('test')->plainTextToken;
        $this->app->make('auth')->forgetGuards();
        $this->defaultHeaders = [];

        return $this->withHeaders(['Authorization' => 'Bearer ' . $token]);
    }

    // ---- the lock on the company's approval ----

    public function test_company_cannot_approve_while_a_required_document_has_no_file(): void
    {
        $this->requiredDoc('السجل التجاري');
        Sanctum::actingAs($this->superAdmin);

        $this->postJson("/api/contracts/{$this->contract->id}/company-approve")
            ->assertStatus(422)
            ->assertJson([
                'code' => 'required_documents_not_approved',
                'documents' => [['name' => 'السجل التجاري', 'state' => 'missing']],
            ]);

        $this->assertSame('client_approved', $this->contract->fresh()->status);
    }

    public function test_company_cannot_approve_while_a_file_is_pending_review(): void
    {
        $doc = $this->requiredDoc('السجل التجاري');
        $this->fileFor($doc, 'pending');
        Sanctum::actingAs($this->superAdmin);

        $this->postJson("/api/contracts/{$this->contract->id}/company-approve")
            ->assertStatus(422)
            ->assertJson(['documents' => [['state' => 'pending']]]);
    }

    public function test_company_cannot_approve_while_every_file_was_rejected(): void
    {
        $doc = $this->requiredDoc('البطاقة');
        $this->fileFor($doc, 'rejected', 'صورة غير واضحة');
        Sanctum::actingAs($this->superAdmin);

        $this->postJson("/api/contracts/{$this->contract->id}/company-approve")
            ->assertStatus(422)
            ->assertJson(['documents' => [['name' => 'البطاقة', 'state' => 'rejected']]]);
    }

    public function test_a_replacement_pending_next_to_a_rejected_file_still_blocks(): void
    {
        $doc = $this->requiredDoc('البطاقة');
        $this->fileFor($doc, 'rejected', 'غير واضحة');
        $this->fileFor($doc, 'pending');
        Sanctum::actingAs($this->superAdmin);

        $this->postJson("/api/contracts/{$this->contract->id}/company-approve")
            ->assertStatus(422)
            ->assertJson(['documents' => [['state' => 'pending']]]);
    }

    public function test_company_approves_once_every_required_document_is_approved(): void
    {
        $this->fileFor($this->requiredDoc('السجل التجاري'), 'approved');
        $this->fileFor($this->requiredDoc('البطاقة'), 'approved');
        Sanctum::actingAs($this->superAdmin);

        $this->postJson("/api/contracts/{$this->contract->id}/company-approve")->assertOk();

        $this->assertSame('company_approved', $this->contract->fresh()->status);
    }

    public function test_an_optional_document_never_blocks(): void
    {
        $this->requiredDoc('خطاب توصية', required: false);
        Sanctum::actingAs($this->superAdmin);

        $this->postJson("/api/contracts/{$this->contract->id}/company-approve")->assertOk();
    }

    public function test_a_manager_allowed_to_approve_is_blocked_the_same_way(): void
    {
        $this->manager->update(['can_approve_contracts' => true]);
        $this->requiredDoc('السجل التجاري');
        Sanctum::actingAs($this->manager);

        $this->postJson("/api/contracts/{$this->contract->id}/company-approve")
            ->assertStatus(422)
            ->assertJson(['code' => 'required_documents_not_approved']);
    }

    public function test_the_clients_own_approval_is_not_held_up_by_pending_files(): void
    {
        $this->contract->update(['status' => 'sent']);
        $this->client->update(['signature_data' => 'data:image/png;base64,abc']);
        $this->fileFor($this->requiredDoc('السجل التجاري'), 'pending');

        $this->asClient()
            ->postJson("/api/contracts/{$this->contract->id}/client-action", ['action' => 'approved'])
            ->assertOk();
    }

    // ---- the notifications ----

    public function test_rejecting_a_file_tells_the_client_with_the_reason_in_both_languages(): void
    {
        Notification::fake();
        $file = $this->fileFor($this->requiredDoc('البطاقة'), 'pending');
        Sanctum::actingAs($this->superAdmin);

        $this->postJson("/api/files/{$file->id}/review", ['action' => 'rejected', 'rejection_reason' => 'صورة غير واضحة'])
            ->assertOk();

        Notification::assertSentTo($this->client, FileRejectedNotification::class, function ($n) {
            $data = $n->toDatabase($this->client);

            return $data['type'] === 'file_rejected'
                && str_contains($data['message'], 'صورة غير واضحة')
                && str_contains($data['message'], 'البطاقة')
                && str_contains($data['message_en'], 'صورة غير واضحة')
                && $data['title_en'] !== ''
                && ! str_contains(json_encode($n->toFcm($this->client)), 'file_url');
        });
    }

    public function test_approving_a_file_does_not_notify_the_client(): void
    {
        Notification::fake();
        $file = $this->fileFor($this->requiredDoc('البطاقة'), 'pending');
        Sanctum::actingAs($this->superAdmin);

        $this->postJson("/api/files/{$file->id}/review", ['action' => 'approved'])->assertOk();

        Notification::assertNothingSent();
    }

    public function test_client_uploading_a_required_document_tells_the_manager_and_super_admin_only(): void
    {
        Notification::fake();
        Storage::fake('public');
        $doc = $this->requiredDoc('السجل التجاري');

        $this->asClient()->post("/api/workspaces/{$this->workspace->id}/files", [
            'file' => UploadedFile::fake()->create('cr.pdf', 20, 'application/pdf'),
            'contract_id' => $this->contract->id,
            'contract_required_document_id' => $doc->id,
        ], ['Accept' => 'application/json'])->assertCreated();

        Notification::assertSentTo($this->manager, ContractDocumentUploadedNotification::class);
        Notification::assertSentTo($this->superAdmin, ContractDocumentUploadedNotification::class);
        Notification::assertNotSentTo($this->otherManager, ContractDocumentUploadedNotification::class);
    }

    public function test_a_plain_upload_without_a_required_document_sends_no_review_notification(): void
    {
        Notification::fake();
        Storage::fake('public');

        $this->asClient()->post("/api/workspaces/{$this->workspace->id}/files", [
            'file' => UploadedFile::fake()->create('notes.pdf', 20, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();

        Notification::assertNothingSent();
    }

    public function test_a_sub_user_without_file_access_never_sees_the_rejection(): void
    {
        $without = SubUser::factory()->create(['client_id' => $this->client->id, 'permissions' => ['can_view_files' => false]]);
        $with = SubUser::factory()->create(['client_id' => $this->client->id, 'permissions' => ['can_view_files' => true]]);

        $this->assertFalse($without->canSeeNotificationType('file_rejected'));
        $this->assertTrue($with->canSeeNotificationType('file_rejected'));
    }

    // ---- the tag the file list shows ----

    public function test_the_file_list_names_the_document_and_the_contract(): void
    {
        $this->fileFor($this->requiredDoc('البطاقة'), 'pending');
        Sanctum::actingAs($this->superAdmin);

        $tag = $this->getJson("/api/workspaces/{$this->workspace->id}/files")->assertOk()->json('files.0.tag');

        $this->assertSame('مستند: البطاقة · Remax SEO', $tag);
    }
}
