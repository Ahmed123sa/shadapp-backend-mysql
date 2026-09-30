<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\ChatMessage;
use App\Models\Client;
use App\Models\Contract;
use App\Models\SubUser;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * New coverage from subuser-review-plan.md — م٢ (only the manager can flip
 * a chat message's requires_action flag) and م٣ (the client's e-signature
 * and contract/approval responses are the client's own act; staff can no
 * longer proxy them, even with a saved signature).
 */
class SubUserReviewPlanTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private User $manager;
    private User $otherManager;
    private Client $client;
    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $this->manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER, 'super_admin_id' => $this->superAdmin->id]);
        $this->otherManager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER, 'super_admin_id' => $this->superAdmin->id]);

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

    private function actingAsClient(Client $client): self
    {
        $this->resetAuth();
        return $this->actingAs($client, 'client');
    }

    private function actingAsClientViaToken(Client $client): self
    {
        $this->resetAuth();
        $this->defaultHeaders = [];
        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $client->createToken('test')->plainTextToken,
        ]);
    }

    private function actingAsSubUser(SubUser $subUser): self
    {
        $this->resetAuth();
        return $this->actingAs($subUser, 'sub_user');
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

    private function makeChatMessage(bool $requiresAction = false): ChatMessage
    {
        return ChatMessage::create([
            'workspace_id' => $this->workspace->id,
            'sender_id' => $this->manager->id,
            'sender_type' => User::class,
            'message' => 'hello',
            'requires_action' => $requiresAction,
        ]);
    }

    // ─── م٢: toggleRequireAction ──────────────────────────────

    public function test_client_cannot_toggle_requires_action_on_their_own_workspace(): void
    {
        $message = $this->makeChatMessage();

        $this->actingAsClient($this->client)
            ->patchJson("/api/chat/{$message->id}/require-action")
            ->assertForbidden();

        $this->assertFalse($message->fresh()->requires_action);
    }

    public function test_sub_user_cannot_toggle_requires_action_even_with_full_permissions(): void
    {
        $subUser = SubUser::create([
            'name' => 'Full Perms',
            'email' => 'full-perms-' . uniqid() . '@example.com',
            'password' => 'password',
            'client_id' => $this->client->id,
            'permissions' => array_fill_keys([
                'can_chat', 'can_view_contracts', 'can_approve_contracts',
                'can_view_payments', 'can_upload_payment_proof', 'can_view_files',
                'can_upload_files', 'can_join_meetings', 'can_respond_approvals',
                'can_view_dashboard', 'can_manage_sub_users',
            ], true),
        ]);
        $message = $this->makeChatMessage();

        $this->actingAsSubUser($subUser)
            ->patchJson("/api/chat/{$message->id}/require-action")
            ->assertForbidden();

        $this->assertFalse($message->fresh()->requires_action);
    }

    public function test_the_workspace_manager_can_toggle_requires_action(): void
    {
        $message = $this->makeChatMessage();

        $this->actingAsManager($this->manager)
            ->patchJson("/api/chat/{$message->id}/require-action")
            ->assertOk();

        $this->assertTrue($message->fresh()->requires_action);
    }

    public function test_a_different_manager_cannot_toggle_requires_action(): void
    {
        $message = $this->makeChatMessage();

        $this->actingAsManager($this->otherManager)
            ->patchJson("/api/chat/{$message->id}/require-action")
            ->assertForbidden();

        $this->assertFalse($message->fresh()->requires_action);
    }

    // ─── م٣: signature is the client's own act ────────────────

    public function test_manager_cannot_sign_on_the_clients_behalf(): void
    {
        $this->actingAsManager($this->manager)
            ->postJson("/api/clients/{$this->client->id}/sign", ['signature' => 'توقيع مزور'])
            ->assertForbidden();

        $this->assertNull($this->client->fresh()->signature_data);
    }

    public function test_client_can_sign_themself(): void
    {
        $this->actingAsClientViaToken($this->client)
            ->postJson("/api/clients/{$this->client->id}/sign", ['signature' => 'توقيعي'])
            ->assertOk();

        $this->assertEquals('توقيعي', $this->client->fresh()->signature_data);
    }

    public function test_manager_cannot_delete_the_clients_signature(): void
    {
        $this->client->update(['signature_data' => 'توقيعي', 'signed_at' => now()]);

        $this->actingAsManager($this->manager)
            ->deleteJson("/api/clients/{$this->client->id}/sign")
            ->assertForbidden();

        $this->assertNotNull($this->client->fresh()->signature_data);
    }

    // ─── م٣: contract client-action is the client's own act ──

    public function test_manager_cannot_approve_a_contract_even_with_a_saved_signature(): void
    {
        $this->client->update(['signature_data' => 'توقيع تجريبي']);
        $contract = Contract::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->manager->id,
            'status' => 'sent',
        ]);

        $this->actingAsManager($this->manager)
            ->postJson("/api/contracts/{$contract->id}/client-action", ['action' => 'approved'])
            ->assertForbidden();

        $this->assertEquals('sent', $contract->fresh()->status);
    }

    public function test_client_can_approve_their_own_contract(): void
    {
        $this->client->update(['signature_data' => 'توقيع تجريبي']);
        $contract = Contract::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->manager->id,
            'status' => 'sent',
        ]);

        $this->actingAsClientViaToken($this->client)
            ->postJson("/api/contracts/{$contract->id}/client-action", ['action' => 'approved'])
            ->assertOk();

        $this->assertEquals('client_approved', $contract->fresh()->status);
    }

    public function test_a_sub_user_with_permission_can_approve_a_contract(): void
    {
        $this->client->update(['signature_data' => 'توقيع تجريبي']);
        $contract = Contract::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->manager->id,
            'status' => 'sent',
        ]);
        $subUser = SubUser::create([
            'name' => 'Approver',
            'email' => 'approver-' . uniqid() . '@example.com',
            'password' => 'password',
            'client_id' => $this->client->id,
            'permissions' => ['can_approve_contracts' => true],
        ]);

        $this->resetAuth();
        $this->defaultHeaders = [];
        $this->withHeaders([
            'Authorization' => 'Bearer ' . $subUser->createToken('test')->plainTextToken,
        ])->postJson("/api/contracts/{$contract->id}/client-action", ['action' => 'approved'])
            ->assertOk();

        $this->assertEquals('client_approved', $contract->fresh()->status);
    }

    // ─── م٣: chat approval response is the client's own act ──

    public function test_manager_cannot_respond_to_an_approval_request(): void
    {
        $this->client->update(['signature_data' => 'توقيع تجريبي']);
        $message = $this->makeChatMessage(requiresAction: true);
        $approval = Approval::create([
            'workspace_id' => $this->workspace->id,
            'title' => 'Approval',
            'approvable_type' => 'chat_message',
            'approvable_id' => $message->id,
            'reference_no' => 'APP-TEST1',
            'requested_by' => $this->manager->id,
            'status' => 'pending',
        ]);
        $message->update(['approval_id' => $approval->id]);

        $this->actingAsManager($this->manager)
            ->postJson("/api/chat/{$message->id}/respond", ['action' => 'approved'])
            ->assertForbidden();

        $this->assertFalse($message->fresh()->action_taken);
    }

    // ─── م٣: staff can still manage the rest of the client's profile ──

    public function test_manager_can_still_update_other_client_fields(): void
    {
        $this->actingAsManager($this->manager)
            ->putJson("/api/clients/{$this->client->id}", [
                'company_name' => 'اسم جديد',
            ])
            ->assertOk();

        $this->assertEquals('اسم جديد', $this->client->fresh()->company_name);
    }

    // ─── م٤: sub-user email/password from the client only ────

    private function makeSubUser(): SubUser
    {
        return SubUser::create([
            'name' => 'Sub User',
            'email' => 'subuser-' . uniqid() . '@example.com',
            'password' => 'password',
            'client_id' => $this->client->id,
            'permissions' => [],
        ]);
    }

    public function test_sub_user_can_change_their_own_name_and_phone(): void
    {
        $subUser = $this->makeSubUser();

        $this->resetAuth();
        $this->actingAs($subUser, 'sub_user')
            ->putJson("/api/sub-users/{$subUser->id}/profile", [
                'name' => 'اسم جديد',
                'phone' => '+966500000000',
            ])
            ->assertOk();

        $this->assertEquals('اسم جديد', $subUser->fresh()->name);
    }

    public function test_sub_user_cannot_change_their_own_email(): void
    {
        $subUser = $this->makeSubUser();
        $originalEmail = $subUser->email;

        $this->resetAuth();
        $this->actingAs($subUser, 'sub_user')
            ->putJson("/api/sub-users/{$subUser->id}/profile", [
                'email' => 'new-email@example.com',
            ])
            ->assertStatus(403);

        $this->assertEquals($originalEmail, $subUser->fresh()->email);
    }

    public function test_client_can_change_a_sub_users_email(): void
    {
        $subUser = $this->makeSubUser();

        $this->resetAuth();
        $this->actingAs($this->client, 'client')
            ->putJson("/api/sub-users/{$subUser->id}/profile", [
                'email' => 'new-email@example.com',
            ])
            ->assertOk();

        $this->assertEquals('new-email@example.com', $subUser->fresh()->email);
    }

    public function test_client_can_change_a_sub_users_password_and_tokens_are_revoked(): void
    {
        $subUser = $this->makeSubUser();
        $subUser->createToken('old-session');

        $this->resetAuth();
        $this->actingAs($this->client, 'client')
            ->patchJson("/api/sub-users/{$subUser->id}/password", [
                'password' => 'NewPassword2',
            ])
            ->assertOk();

        $this->assertSame(0, $subUser->tokens()->count());
    }

    // ─── م٧: no shared email across users/clients/sub_users ──

    public function test_a_sub_user_cannot_take_a_clients_email(): void
    {
        $otherClient = Client::factory()->create(['manager_id' => $this->manager->id, 'email' => 'shared@example.com']);

        $this->resetAuth();
        $this->actingAs($this->client, 'client')
            ->postJson("/api/clients/{$this->client->id}/sub-users", [
                'name' => 'New Sub User',
                'email' => 'shared@example.com',
                'password' => 'Password1',
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('sub_users', ['email' => 'shared@example.com']);
    }

    public function test_a_client_cannot_take_a_sub_users_email(): void
    {
        $subUser = SubUser::create([
            'name' => 'Existing Sub User',
            'email' => 'shared2@example.com',
            'password' => 'password',
            'client_id' => $this->client->id,
            'permissions' => [],
        ]);

        $this->actingAsManager($this->manager)
            ->postJson('/api/clients', [
                'company_name' => 'شركة جديدة',
                'contact_person' => 'Contact',
                'email' => 'shared2@example.com',
                'phone' => '+966500000099',
            ])
            ->assertStatus(422);
    }

    public function test_emails_are_compared_case_insensitively(): void
    {
        Client::factory()->create(['manager_id' => $this->manager->id, 'email' => 'ahmed@example.com']);

        $this->resetAuth();
        $this->actingAs($this->client, 'client')
            ->postJson("/api/clients/{$this->client->id}/sub-users", [
                'name' => 'New Sub User',
                'email' => 'AHMED@example.com',
                'password' => 'Password1',
            ])
            ->assertStatus(422);
    }

    public function test_updating_a_sub_user_with_their_own_unchanged_email_still_works(): void
    {
        $subUser = $this->makeSubUser();

        $this->resetAuth();
        $this->actingAs($this->client, 'client')
            ->putJson("/api/sub-users/{$subUser->id}/profile", [
                'name' => 'اسم محدث',
                'email' => $subUser->email,
            ])
            ->assertOk();
    }
}
