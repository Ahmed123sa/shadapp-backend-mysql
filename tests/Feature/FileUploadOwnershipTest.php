<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractRequiredDocument;
use App\Models\DocumentDefinition;
use App\Models\FileEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * An upload may only point at contracts, required documents and document
 * definitions of the workspace it is uploaded to.
 */
class FileUploadOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private Client $clientA;
    private Client $clientB;
    private Workspace $wsA;
    private Workspace $wsB;
    private Contract $contractA;
    private Contract $contractB;
    private ContractRequiredDocument $docA;
    private ContractRequiredDocument $docB;
    private DocumentDefinition $defA;
    private DocumentDefinition $defB;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $manager = User::factory()->create([
            'role' => User::ROLE_ACCOUNT_MANAGER,
            'super_admin_id' => $admin->id,
            'is_active' => true,
        ]);

        foreach (['A', 'B'] as $k) {
            $client = Client::factory()->create(['manager_id' => $manager->id]);
            $ws = Workspace::factory()->create(['client_id' => $client->id, 'manager_id' => $manager->id, 'status' => 'inactive']);
            $contract = Contract::factory()->create(['workspace_id' => $ws->id, 'status' => 'client_approved', 'created_by' => $manager->id]);
            $doc = ContractRequiredDocument::create(['contract_id' => $contract->id, 'name' => "doc $k", 'is_required' => true, 'sort_order' => 0]);
            $def = DocumentDefinition::create(['workspace_id' => $ws->id, 'name' => "def $k", 'is_required' => false, 'sort_order' => 0]);

            $this->{'client'.$k} = $client;
            $this->{'ws'.$k} = $ws;
            $this->{'contract'.$k} = $contract;
            $this->{'doc'.$k} = $doc;
            $this->{'def'.$k} = $def;
        }
    }

    private function asClientA(): self
    {
        $token = $this->clientA->createToken('test')->plainTextToken;
        $this->app->make('auth')->forgetGuards();
        $this->defaultHeaders = [];

        return $this->withHeaders(['Authorization' => 'Bearer '.$token]);
    }

    private function upload(array $extra)
    {
        return $this->asClientA()->post("/api/workspaces/{$this->wsA->id}/files", array_merge([
            'file' => UploadedFile::fake()->create('x.pdf', 20, 'application/pdf'),
        ], $extra), ['Accept' => 'application/json']);
    }

    public function test_a_file_cannot_be_attached_to_another_workspaces_required_document(): void
    {
        Notification::fake();
        Storage::fake('public');

        $this->upload(['contract_required_document_id' => $this->docB->id])
            ->assertStatus(422)->assertJsonValidationErrors('contract_required_document_id');

        $this->assertSame(0, FileEntry::count());
        Notification::assertNothingSent();
    }

    public function test_a_file_cannot_be_attached_to_another_workspaces_contract_or_definition(): void
    {
        Storage::fake('public');

        $this->upload(['contract_id' => $this->contractB->id])
            ->assertStatus(422)->assertJsonValidationErrors('contract_id');
        $this->upload(['document_definition_id' => $this->defB->id])
            ->assertStatus(422)->assertJsonValidationErrors('document_definition_id');

        $this->assertSame(0, FileEntry::count());
    }

    public function test_the_workspaces_own_ids_still_work(): void
    {
        Storage::fake('public');

        $this->upload([
            'contract_id' => $this->contractA->id,
            'contract_required_document_id' => $this->docA->id,
            'document_definition_id' => $this->defA->id,
        ])->assertCreated();

        $this->assertSame(1, $this->docA->files()->count());
    }

    public function test_an_old_file_pointing_at_another_workspaces_document_does_not_count(): void
    {
        // What the unscoped upload could leave behind: a file of workspace A
        // that names workspace B's required document.
        FileEntry::create([
            'workspace_id' => $this->wsA->id,
            'contract_id' => $this->contractB->id,
            'contract_required_document_id' => $this->docB->id,
            'uploaded_by_type' => Client::class,
            'uploaded_by_id' => $this->clientA->id,
            'file_url' => '/storage/x.pdf',
            'name' => 'x.pdf',
            'status' => 'approved',
        ]);

        $this->assertSame(0, $this->docB->files()->count());
        $this->assertSame(1, $this->contractB->requiredDocuments()
            ->whereDoesntHave('files', fn ($q) => $q->where('status', '!=', 'rejected'))->count());
    }
}
