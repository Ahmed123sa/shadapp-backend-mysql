<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\ChatMessage;
use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractClause;
use App\Models\DocumentDefinition;
use App\Models\FileEntry;
use App\Models\Meeting;
use App\Models\Payment;
use App\Models\SubUser;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression suite for the cross-tenant IDOR fixed by ScopeWorkspace
 * middleware (app/Http/Middleware/ScopeWorkspace.php) plus the handful of
 * controller actions that bind a resource without {workspace} in the URL
 * (ChatController::respond/toggleRequireAction, ApprovalController::respond,
 * ContractController::clientAction/requiredDocuments/files, and the
 * payment-schedule endpoints).
 *
 * The bug: every workspace-nested endpoint checked that the caller was
 * *authenticated*, never that the caller *owned* the workspace in the URL.
 * A client's own valid Sanctum token plus another workspace's numeric ID
 * was enough to read/write another company's chat, files, contracts and
 * payments. This suite asserts the fix holds for every affected route, and
 * is meant to fail loudly the moment a future PR reopens the gap on a new
 * or existing endpoint.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private User $managerA;
    private User $managerB;
    private Client $clientA;
    private Client $clientB;
    private Workspace $workspaceA;
    private Workspace $workspaceB;
    private SubUser $subUserA;

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

        $this->subUserA = SubUser::create([
            'name' => 'Sub User A',
            'email' => 'subuser-a-' . uniqid() . '@example.com',
            'password' => 'password',
            'client_id' => $this->clientA->id,
            'permissions' => ['can_respond_approvals' => true],
        ]);
    }

    private function resetAuth(): void
    {
        $this->app->make('auth')->forgetGuards();
    }

    private function actingAsClient(Client $client): self
    {
        $this->resetAuth();
        return $this->actingAs($client, 'client');
    }

    private function actingAsSubUser(SubUser $subUser): self
    {
        $this->resetAuth();
        return $this->actingAs($subUser, 'sub_user');
    }

    /**
     * Some client-facing routes live in the `auth:sanctum` group rather than
     * the multi-guard `auth.any` group — /contracts/{contract}/client-action
     * and POST /workspaces/{workspace}/approvals among them. Real clients reach
     * those with a Sanctum bearer token, which resolves to the Client model.
     *
     * actingAs($client, 'client') only populates the `client` guard, so the
     * sanctum guard sees nobody and the request 401s before authorization ever
     * runs — which would silently pass an isolation test for the wrong reason.
     * Authenticating the way production does keeps the assertion meaningful.
     */
    private function actingAsClientViaToken(Client $client): self
    {
        $this->resetAuth();
        $this->defaultHeaders = [];

        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $client->createToken('isolation-test')->plainTextToken,
        ]);
    }

    private function actingAsManager(User $user): self
    {
        $this->resetAuth();
        return $this->actingAs($user, 'sanctum');
    }

    private function actingAsSA(): self
    {
        $this->resetAuth();
        return $this->actingAs($this->superAdmin, 'sanctum');
    }

    // ─── Chat ──────────────────────────────────────────────────

    public function test_client_cannot_list_another_clients_chat(): void
    {
        $this->actingAsClient($this->clientA)
            ->getJson("/api/workspaces/{$this->workspaceB->id}/chat")
            ->assertForbidden();
    }

    public function test_client_cannot_post_chat_to_another_clients_workspace(): void
    {
        $this->actingAsClient($this->clientA)
            ->postJson("/api/workspaces/{$this->workspaceB->id}/chat", ['message' => 'hi'])
            ->assertForbidden();
    }

    public function test_client_cannot_mark_another_clients_chat_read(): void
    {
        $this->actingAsClient($this->clientA)
            ->postJson("/api/workspaces/{$this->workspaceB->id}/chat/mark-read")
            ->assertForbidden();
    }

    public function test_client_cannot_respond_to_another_clients_chat_message(): void
    {
        $message = ChatMessage::create([
            'workspace_id' => $this->workspaceB->id,
            'sender_type' => User::class,
            'sender_id' => $this->managerB->id,
            'message' => 'approve please',
            'requires_action' => true,
        ]);

        $this->actingAsClient($this->clientA)
            ->postJson("/api/chat/{$message->id}/respond", ['action' => 'approved'])
            ->assertForbidden();
    }

    public function test_client_cannot_toggle_require_action_on_another_clients_chat_message(): void
    {
        $message = ChatMessage::create([
            'workspace_id' => $this->workspaceB->id,
            'sender_type' => User::class,
            'sender_id' => $this->managerB->id,
            'message' => 'hello',
        ]);

        $this->actingAsClient($this->clientA)
            ->patchJson("/api/chat/{$message->id}/require-action")
            ->assertForbidden();
    }

    // ─── Files ─────────────────────────────────────────────────

    public function test_client_cannot_list_another_clients_files(): void
    {
        $this->actingAsClient($this->clientA)
            ->getJson("/api/workspaces/{$this->workspaceB->id}/files")
            ->assertForbidden();
    }

    public function test_client_cannot_upload_file_to_another_clients_workspace(): void
    {
        $this->actingAsClient($this->clientA)
            ->postJson("/api/workspaces/{$this->workspaceB->id}/files", [])
            ->assertForbidden();
    }

    public function test_client_cannot_delete_file_via_mismatched_workspace_and_correct_workspace_still_scoped(): void
    {
        $fileInB = FileEntry::create([
            'workspace_id' => $this->workspaceB->id,
            'uploaded_by_type' => Client::class,
            'uploaded_by_id' => $this->clientB->id,
            'file_url' => '/storage/x.pdf',
            'name' => 'x.pdf',
            'status' => 'pending',
        ]);

        // Client A tries to delete a file that belongs to workspace B, even
        // when addressing it through their own workspace A in the URL.
        $this->actingAsClient($this->clientA)
            ->deleteJson("/api/workspaces/{$this->workspaceA->id}/files/{$fileInB->id}")
            ->assertNotFound();

        // And directly through workspace B's URL, ownership is blocked first.
        $this->actingAsClient($this->clientA)
            ->deleteJson("/api/workspaces/{$this->workspaceB->id}/files/{$fileInB->id}")
            ->assertForbidden();
    }

    // ─── Payments ──────────────────────────────────────────────

    public function test_client_cannot_list_another_clients_payments(): void
    {
        $this->actingAsClient($this->clientA)
            ->getJson("/api/workspaces/{$this->workspaceB->id}/payments")
            ->assertForbidden();
    }

    public function test_client_cannot_submit_payment_to_another_clients_workspace(): void
    {
        $this->actingAsClient($this->clientA)
            ->postJson("/api/workspaces/{$this->workspaceB->id}/payments", [
                'amount' => 100,
                'method_type' => 'bank_transfer',
            ])
            ->assertForbidden();
    }

    public function test_client_cannot_update_another_clients_payment(): void
    {
        $payment = Payment::factory()->create([
            'workspace_id' => $this->workspaceB->id,
            'client_id' => $this->clientB->id,
            'status' => 'pending',
        ]);

        $this->actingAsClient($this->clientA)
            ->putJson("/api/workspaces/{$this->workspaceB->id}/payments/{$payment->id}", ['amount' => 1])
            ->assertForbidden();
    }

    public function test_client_cannot_view_another_clients_payment_schedule(): void
    {
        $this->actingAsClient($this->clientA)
            ->getJson("/api/workspaces/{$this->workspaceB->id}/payment-schedule")
            ->assertForbidden();
    }

    public function test_manager_cannot_schedule_payment_for_workspace_they_do_not_manage(): void
    {
        $this->actingAsManager($this->managerA)
            ->postJson("/api/workspaces/{$this->workspaceB->id}/payments/schedule", [
                'installments' => [['amount' => 100, 'due_date' => now()->addDays(5)->toDateString()]],
            ])
            ->assertForbidden();
    }

    public function test_manager_cannot_request_payment_for_workspace_they_do_not_manage(): void
    {
        $this->actingAsManager($this->managerA)
            ->postJson("/api/workspaces/{$this->workspaceB->id}/payments/request", ['amount' => 100])
            ->assertForbidden();
    }

    public function test_manager_cannot_update_payment_schedule_belonging_to_another_manager(): void
    {
        $payment = Payment::factory()->create([
            'workspace_id' => $this->workspaceB->id,
            'client_id' => $this->clientB->id,
            'status' => 'scheduled',
        ]);

        $this->actingAsManager($this->managerA)
            ->putJson("/api/payments/{$payment->id}/schedule", ['amount' => 1])
            ->assertForbidden();
    }

    public function test_manager_cannot_delete_payment_schedule_belonging_to_another_manager(): void
    {
        $payment = Payment::factory()->create([
            'workspace_id' => $this->workspaceB->id,
            'client_id' => $this->clientB->id,
            'status' => 'scheduled',
        ]);

        $this->actingAsManager($this->managerA)
            ->deleteJson("/api/payments/{$payment->id}/schedule")
            ->assertForbidden();
    }

    // ─── Contracts ─────────────────────────────────────────────

    public function test_client_cannot_list_another_clients_contracts(): void
    {
        $this->actingAsClient($this->clientA)
            ->getJson("/api/workspaces/{$this->workspaceB->id}/contracts")
            ->assertForbidden();
    }

    public function test_client_cannot_act_on_another_clients_contract(): void
    {
        $contract = Contract::factory()->withCreator($this->managerB)->create([
            'workspace_id' => $this->workspaceB->id,
            'status' => 'sent',
        ]);

        $this->actingAsClientViaToken($this->clientA)
            ->postJson("/api/contracts/{$contract->id}/client-action", ['action' => 'approved'])
            ->assertForbidden();

        $this->assertSame('sent', $contract->fresh()->status, 'A foreign client must not be able to change contract status.');
    }

    public function test_client_cannot_read_required_documents_of_another_clients_contract(): void
    {
        $contract = Contract::factory()->withCreator($this->managerB)->create(['workspace_id' => $this->workspaceB->id]);

        $this->actingAsClient($this->clientA)
            ->getJson("/api/contracts/{$contract->id}/required-documents")
            ->assertForbidden();
    }

    public function test_client_cannot_read_files_of_another_clients_contract(): void
    {
        $contract = Contract::factory()->withCreator($this->managerB)->create(['workspace_id' => $this->workspaceB->id]);

        $this->actingAsClient($this->clientA)
            ->getJson("/api/contracts/{$contract->id}/files")
            ->assertForbidden();
    }

    public function test_manager_cannot_add_clause_to_contract_they_do_not_manage(): void
    {
        $contract = Contract::factory()->withCreator($this->managerB)->create(['workspace_id' => $this->workspaceB->id]);

        $this->actingAsManager($this->managerA)
            ->postJson("/api/contracts/{$contract->id}/clauses", ['content' => 'injected clause'])
            ->assertForbidden();
    }

    public function test_manager_cannot_modify_clause_belonging_to_contract_they_do_not_manage(): void
    {
        $contractA = Contract::factory()->withCreator($this->managerA)->create(['workspace_id' => $this->workspaceA->id]);
        $contractB = Contract::factory()->withCreator($this->managerB)->create(['workspace_id' => $this->workspaceB->id]);

        $clauseB = ContractClause::create(['contract_id' => $contractB->id, 'content' => 'original']);

        // Wrong manager entirely.
        $this->actingAsManager($this->managerA)
            ->putJson("/api/contracts/{$contractB->id}/clauses/{$clauseB->id}", ['content' => 'hacked'])
            ->assertForbidden();

        // Own contract, but someone else's clause id smuggled in via the URL.
        $this->actingAsManager($this->managerA)
            ->putJson("/api/contracts/{$contractA->id}/clauses/{$clauseB->id}", ['content' => 'hacked'])
            ->assertNotFound();
    }

    // ─── Approvals ─────────────────────────────────────────────

    public function test_client_cannot_list_another_clients_approvals(): void
    {
        $this->actingAsClient($this->clientA)
            ->getJson("/api/workspaces/{$this->workspaceB->id}/approvals")
            ->assertForbidden();
    }

    public function test_client_cannot_create_approval_in_another_clients_workspace(): void
    {
        $this->actingAsClientViaToken($this->clientA)
            ->postJson("/api/workspaces/{$this->workspaceB->id}/approvals", ['title' => 'x', 'description' => 'y'])
            ->assertForbidden();

        $this->assertDatabaseMissing('approvals', ['workspace_id' => $this->workspaceB->id, 'title' => 'x']);
    }

    public function test_client_cannot_respond_to_another_clients_approval(): void
    {
        $approval = Approval::factory()->create([
            'workspace_id' => $this->workspaceB->id,
            'requested_by' => $this->managerB->id,
        ]);

        $this->actingAsClient($this->clientA)
            ->postJson("/api/approvals/{$approval->id}/respond", ['action' => 'approved'])
            ->assertForbidden();
    }

    public function test_sub_user_cannot_respond_to_another_clients_approval(): void
    {
        $approval = Approval::factory()->create([
            'workspace_id' => $this->workspaceB->id,
            'requested_by' => $this->managerB->id,
        ]);

        $this->actingAsSubUser($this->subUserA)
            ->postJson("/api/approvals/{$approval->id}/respond", ['action' => 'approved'])
            ->assertForbidden();
    }

    // ─── Meetings ──────────────────────────────────────────────

    public function test_client_cannot_list_another_clients_meetings(): void
    {
        $this->actingAsClient($this->clientA)
            ->getJson("/api/workspaces/{$this->workspaceB->id}/meetings")
            ->assertForbidden();
    }

    // ─── Document definitions ──────────────────────────────────

    public function test_manager_cannot_create_document_definition_for_workspace_they_do_not_manage(): void
    {
        $this->actingAsManager($this->managerA)
            ->postJson("/api/workspaces/{$this->workspaceB->id}/document-definitions", ['name' => 'ID Card'])
            ->assertForbidden();
    }

    public function test_manager_cannot_delete_document_definition_via_mismatched_workspace(): void
    {
        $defInB = DocumentDefinition::create([
            'workspace_id' => $this->workspaceB->id,
            'name' => 'Passport',
            'is_required' => true,
            'sort_order' => 0,
        ]);

        // Manager A addresses it through their own workspace A — must 404, not leak.
        $this->actingAsManager($this->managerA)
            ->deleteJson("/api/workspaces/{$this->workspaceA->id}/document-definitions/{$defInB->id}")
            ->assertNotFound();

        // And directly through workspace B, ownership blocks it first.
        $this->actingAsManager($this->managerA)
            ->deleteJson("/api/workspaces/{$this->workspaceB->id}/document-definitions/{$defInB->id}")
            ->assertForbidden();
    }

    // ─── Sub-user tenant boundary ──────────────────────────────

    public function test_sub_user_cannot_access_a_workspace_outside_their_own_client(): void
    {
        $this->actingAsSubUser($this->subUserA)
            ->getJson("/api/workspaces/{$this->workspaceB->id}/chat")
            ->assertForbidden();
    }

    public function test_sub_user_can_access_their_own_clients_workspace(): void
    {
        $this->actingAsSubUser($this->subUserA)
            ->getJson("/api/workspaces/{$this->workspaceA->id}/chat")
            ->assertOk();
    }

    // ─── Positive controls: legitimate access still works ──────

    public function test_client_can_access_their_own_workspace_chat(): void
    {
        $this->actingAsClient($this->clientA)
            ->getJson("/api/workspaces/{$this->workspaceA->id}/chat")
            ->assertOk();
    }

    public function test_manager_can_access_workspace_they_manage(): void
    {
        $this->actingAsManager($this->managerA)
            ->getJson("/api/workspaces/{$this->workspaceA->id}/contracts")
            ->assertOk();
    }

    public function test_super_admin_can_access_any_workspace(): void
    {
        $this->actingAsSA()
            ->getJson("/api/workspaces/{$this->workspaceB->id}/contracts")
            ->assertOk();

        $this->actingAsSA()
            ->getJson("/api/workspaces/{$this->workspaceA->id}/contracts")
            ->assertOk();
    }
}
