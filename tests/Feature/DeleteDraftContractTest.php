<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Contract;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A contract can be deleted only while it is a draft: saved, never sent.
 * Every other status stays on record.
 */
class DeleteDraftContractTest extends TestCase
{
    use RefreshDatabase;

    private User $am;
    private Workspace $ws;

    protected function setUp(): void
    {
        parent::setUp();
        $this->am = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $client = Client::factory()->create(['manager_id' => $this->am->id]);
        $this->ws = Workspace::factory()->create(['client_id' => $client->id, 'manager_id' => $this->am->id]);
    }

    private function contract(string $status): Contract
    {
        return Contract::factory()->create([
            'workspace_id' => $this->ws->id,
            'created_by' => $this->am->id,
            'status' => $status,
            'value' => 1000,
        ]);
    }

    public function test_the_manager_deletes_a_draft_with_its_clauses_and_documents(): void
    {
        Sanctum::actingAs($this->am);
        $c = $this->contract('draft');
        $c->clauses()->create(['content' => 'clause', 'type' => 'custom', 'sort_order' => 0]);
        $c->requiredDocuments()->create(['name' => 'ID', 'is_required' => true, 'sort_order' => 0]);

        $this->deleteJson("/api/contracts/{$c->id}")->assertOk();

        $this->assertDatabaseMissing('contracts', ['id' => $c->id]);
        $this->assertDatabaseMissing('contract_clauses', ['contract_id' => $c->id]);
        $this->assertDatabaseMissing('contract_required_documents', ['contract_id' => $c->id]);
        $this->assertTrue(AuditLog::where('action', 'contract.deleted')->where('auditable_id', $c->id)->exists());
    }

    #[DataProvider('lockedStatuses')]
    public function test_a_contract_that_was_sent_cannot_be_deleted(string $status): void
    {
        Sanctum::actingAs($this->am);
        $c = $this->contract($status);

        $this->deleteJson("/api/contracts/{$c->id}")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'contract_not_deletable');

        $this->assertDatabaseHas('contracts', ['id' => $c->id, 'status' => $status]);
    }

    public static function lockedStatuses(): array
    {
        return [
            'sent' => ['sent'],
            'edit requested' => ['edit_requested'],
            'client approved' => ['client_approved'],
            'company approved' => ['company_approved'],
            'completed' => ['completed'],
            'archived' => ['archived'],
        ];
    }

    public function test_another_manager_cannot_delete_the_draft(): void
    {
        $other = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        Sanctum::actingAs($other);
        $c = $this->contract('draft');

        $this->deleteJson("/api/contracts/{$c->id}")->assertForbidden();

        $this->assertDatabaseHas('contracts', ['id' => $c->id]);
    }

    public function test_the_client_cannot_delete_the_draft(): void
    {
        $c = $this->contract('draft');
        Sanctum::actingAs(Client::find($this->ws->client_id));

        $this->deleteJson("/api/contracts/{$c->id}")->assertStatus(403);

        $this->assertDatabaseHas('contracts', ['id' => $c->id]);
    }
}
