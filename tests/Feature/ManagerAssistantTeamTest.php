<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Team management and the assistant lifecycle (MANAGER_ASSISTANT_PLAN.md م٣).
 */
class ManagerAssistantTeamTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private User $manager;
    private User $otherManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $this->manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER, 'super_admin_id' => $this->superAdmin->id]);
        $this->otherManager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER, 'super_admin_id' => $this->superAdmin->id]);
    }

    private function assistantOf(User $manager, array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'role' => User::ROLE_MANAGER_ASSISTANT,
            'parent_manager_id' => $manager->id,
            'assistant_permissions' => ['can_view_clients' => true],
            'is_active' => true,
            'password' => 'Password123',
        ], $attrs));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Sami',
            'email' => 'Sami@Example.com',
            'password' => 'Password123',
            'phone' => '0100000000',
            'permissions' => ['can_chat' => true, 'can_manage_contracts' => true, 'bogus_key' => true],
        ], $overrides);
    }

    // ── ت٦ — a manager manages their own team ──

    public function test_a_manager_creates_an_assistant(): void
    {
        Sanctum::actingAs($this->manager);

        $response = $this->postJson('/api/team', $this->payload())->assertCreated();

        $response->assertJsonPath('assistant.email', 'sami@example.com')
            ->assertJsonPath('assistant.role', 'manager_assistant')
            ->assertJsonPath('assistant.permissions.can_chat', true)
            ->assertJsonPath('assistant.permissions.can_manage_contracts', true)
            ->assertJsonPath('assistant.permissions.can_manage_meetings', false)
            // always on, never optional
            ->assertJsonPath('assistant.permissions.can_view_clients', true)
            ->assertJsonMissingPath('assistant.permissions.bogus_key')
            ->assertJsonMissingPath('assistant.password');

        $this->assertDatabaseHas('users', [
            'email' => 'sami@example.com',
            'role' => 'manager_assistant',
            'parent_manager_id' => $this->manager->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'team.assistant_created',
            'user_id' => $this->manager->id,
        ]);
    }

    public function test_the_new_assistant_can_log_in_and_sees_their_manager_only(): void
    {
        Sanctum::actingAs($this->manager);
        $this->postJson('/api/team', $this->payload())->assertCreated();
        Client::factory()->create(['manager_id' => $this->manager->id]);
        Client::factory()->create(['manager_id' => $this->otherManager->id]);

        $login = $this->postJson('/api/auth/login', ['email' => 'sami@example.com', 'password' => 'Password123'])->assertOk();
        $this->assertSame('manager_assistant', $login->json('user.role'));

        $this->app['auth']->forgetGuards();
        $this->withToken($login->json('token'))->getJson('/api/clients')->assertOk()->assertJsonCount(1, 'clients.data');
    }

    public function test_the_email_must_be_unique_across_every_login_table(): void
    {
        Sanctum::actingAs($this->manager);
        $client = Client::factory()->create(['manager_id' => $this->manager->id, 'email' => 'taken@example.com']);

        $this->postJson('/api/team', $this->payload(['email' => 'taken@example.com']))->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/team', $this->payload(['email' => $this->manager->email]))->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_a_weak_password_is_rejected(): void
    {
        Sanctum::actingAs($this->manager);

        $this->postJson('/api/team', $this->payload(['password' => 'short']))->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_a_manager_cannot_exceed_the_assistant_limit(): void
    {
        config(['team.max_assistants' => 2]);
        $this->assistantOf($this->manager);
        $this->assistantOf($this->manager, ['is_active' => false]); // deactivated still counts
        $this->assistantOf($this->otherManager);                    // other teams don't
        Sanctum::actingAs($this->manager);

        $this->postJson('/api/team', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('limit');
        $this->assertSame(2, User::where('parent_manager_id', $this->manager->id)->count());
    }

    public function test_removing_an_assistant_is_a_soft_removal_that_keeps_their_name(): void
    {
        $assistant = $this->assistantOf($this->manager, ['name' => 'Sami Helper', 'email' => 'sami@example.com']);
        $assistant->createToken('t');
        Sanctum::actingAs($this->manager);

        $this->deleteJson("/api/team/{$assistant->id}")->assertOk();

        $fresh = User::find($assistant->id);
        $this->assertNotNull($fresh);                       // row kept
        $this->assertSame('Sami Helper', $fresh->name);     // name kept for the audit log
        $this->assertNotNull($fresh->removed_at);
        $this->assertFalse((bool) $fresh->is_active);
        $this->assertNotSame('sami@example.com', $fresh->email);
        $this->assertSame(0, $fresh->tokens()->count());
    }

    public function test_a_removed_assistant_is_gone_from_the_team_and_cannot_log_in(): void
    {
        $assistant = $this->assistantOf($this->manager, ['email' => 'sami@example.com', 'password' => 'Password123']);
        Sanctum::actingAs($this->manager);
        $this->deleteJson("/api/team/{$assistant->id}")->assertOk();

        $this->getJson('/api/team')->assertOk()->assertJsonCount(0, 'assistants');

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['email' => 'sami@example.com', 'password' => 'Password123'])->assertStatus(422);
    }

    public function test_a_removed_assistants_email_can_be_used_again_and_the_slot_is_freed(): void
    {
        config(['team.max_assistants' => 1]);
        $assistant = $this->assistantOf($this->manager, ['email' => 'sami@example.com']);
        Sanctum::actingAs($this->manager);

        $this->postJson('/api/team', $this->payload(['email' => 'new@example.com']))->assertUnprocessable()->assertJsonValidationErrors('limit');

        $this->deleteJson("/api/team/{$assistant->id}")->assertOk();

        $this->postJson('/api/team', $this->payload(['email' => 'sami@example.com']))->assertCreated();
    }

    public function test_the_audit_log_still_names_a_removed_assistant(): void
    {
        $assistant = $this->assistantOf($this->manager, ['name' => 'Sami Helper']);
        AuditLog::create([
            'auditable_type' => User::class, 'auditable_id' => $assistant->id, 'user_id' => $assistant->id,
            'action' => 'contract.sent', 'ip_address' => '127.0.0.1',
        ]);
        Sanctum::actingAs($this->manager);
        $this->deleteJson("/api/team/{$assistant->id}")->assertOk();

        $rows = $this->getJson('/api/audit-logs')->assertOk()->json('logs.data');
        $mine = collect($rows)->firstWhere('action', 'contract.sent');

        $this->assertSame('Sami Helper', $mine['user']['name']);
        $this->assertSame($this->manager->name, $mine['user']['assistant_of']);
        $this->assertTrue(collect($rows)->contains('action', 'team.assistant_removed'));
    }

    public function test_only_the_owning_manager_can_remove_an_assistant_and_only_once(): void
    {
        $assistant = $this->assistantOf($this->manager);

        Sanctum::actingAs($this->otherManager);
        $this->deleteJson("/api/team/{$assistant->id}")->assertNotFound();

        Sanctum::actingAs($this->superAdmin);
        $this->deleteJson("/api/team/{$assistant->id}")->assertForbidden();

        Sanctum::actingAs($this->manager);
        $this->deleteJson("/api/team/{$assistant->id}")->assertOk();
        $this->deleteJson("/api/team/{$assistant->id}")->assertNotFound();
        $this->postJson("/api/team/{$assistant->id}/activate")->assertNotFound();
        $this->patchJson("/api/team/{$assistant->id}/password", ['password' => 'NewPassword1'])->assertNotFound();
    }

    public function test_an_assistant_cannot_remove_anyone(): void
    {
        $assistant = $this->assistantOf($this->manager);
        $other = $this->assistantOf($this->manager);
        Sanctum::actingAs($assistant);

        $this->deleteJson("/api/team/{$other->id}")->assertForbidden();
    }

    public function test_a_manager_lists_only_their_own_assistants(): void
    {
        $mine = $this->assistantOf($this->manager);
        $this->assistantOf($this->otherManager);
        Sanctum::actingAs($this->manager);

        $this->getJson('/api/team')->assertOk()->assertJsonCount(1, 'assistants')->assertJsonPath('assistants.0.id', $mine->id);
    }

    public function test_a_manager_updates_data_and_permissions(): void
    {
        $assistant = $this->assistantOf($this->manager, ['assistant_permissions' => ['can_view_clients' => true, 'can_chat' => true]]);
        Sanctum::actingAs($this->manager);

        $this->putJson("/api/team/{$assistant->id}", [
            'name' => 'New Name',
            'permissions' => ['can_manage_meetings' => true, 'can_chat' => false],
        ])->assertOk()
            ->assertJsonPath('assistant.name', 'New Name')
            ->assertJsonPath('assistant.permissions.can_manage_meetings', true)
            ->assertJsonPath('assistant.permissions.can_chat', false);

        $this->assertDatabaseHas('audit_logs', ['action' => 'team.assistant_updated', 'auditable_id' => $assistant->id]);
    }

    public function test_permissions_not_sent_keep_their_value(): void
    {
        $assistant = $this->assistantOf($this->manager, ['assistant_permissions' => ['can_view_clients' => true, 'can_chat' => true]]);
        Sanctum::actingAs($this->manager);

        $this->putJson("/api/team/{$assistant->id}", ['permissions' => ['can_manage_meetings' => true]])
            ->assertOk()
            ->assertJsonPath('assistant.permissions.can_chat', true);
    }

    public function test_deactivate_blocks_login_and_kills_sessions_and_activate_restores(): void
    {
        $assistant = $this->assistantOf($this->manager);
        $assistant->createToken('t');
        Sanctum::actingAs($this->manager);

        $this->postJson("/api/team/{$assistant->id}/deactivate")->assertOk()->assertJsonPath('assistant.is_active', false);
        $this->assertSame(0, $assistant->tokens()->count());
        $this->postJson('/api/auth/login', ['email' => $assistant->email, 'password' => 'Password123'])->assertUnprocessable();

        $this->postJson("/api/team/{$assistant->id}/activate")->assertOk()->assertJsonPath('assistant.is_active', true);
        $this->postJson('/api/auth/login', ['email' => $assistant->email, 'password' => 'Password123'])->assertOk();
    }

    public function test_changing_the_password_revokes_sessions(): void
    {
        $assistant = $this->assistantOf($this->manager);
        $assistant->createToken('t');
        Sanctum::actingAs($this->manager);

        $this->patchJson("/api/team/{$assistant->id}/password", ['password' => 'Another123'])->assertOk();

        $this->assertSame(0, $assistant->tokens()->count());
        $this->postJson('/api/auth/login', ['email' => $assistant->email, 'password' => 'Another123'])->assertOk();
    }

    public function test_the_activity_log_lists_what_the_assistant_did(): void
    {
        $assistant = $this->assistantOf($this->manager);
        AuditLog::create(['auditable_type' => User::class, 'auditable_id' => $assistant->id, 'user_id' => $assistant->id, 'action' => 'contract.sent', 'ip_address' => '1.1.1.1']);
        AuditLog::create(['auditable_type' => User::class, 'auditable_id' => $this->manager->id, 'user_id' => $this->manager->id, 'action' => 'contract.sent', 'ip_address' => '1.1.1.1']);
        Sanctum::actingAs($this->manager);

        $this->getJson("/api/team/{$assistant->id}/activity")->assertOk()->assertJsonCount(1, 'logs.data')
            ->assertJsonPath('logs.data.0.user_id', $assistant->id);
    }

    public function test_a_manager_cannot_touch_another_managers_assistant(): void
    {
        $theirs = $this->assistantOf($this->otherManager);
        Sanctum::actingAs($this->manager);

        $this->putJson("/api/team/{$theirs->id}", ['name' => 'x'])->assertNotFound();
        $this->postJson("/api/team/{$theirs->id}/deactivate")->assertNotFound();
        $this->postJson("/api/team/{$theirs->id}/activate")->assertNotFound();
        $this->patchJson("/api/team/{$theirs->id}/password", ['password' => 'Another123'])->assertNotFound();
        $this->getJson("/api/team/{$theirs->id}/activity")->assertNotFound();
        $this->assertTrue($theirs->fresh()->is_active);
    }

    public function test_the_team_routes_do_not_accept_a_non_assistant_id(): void
    {
        Sanctum::actingAs($this->manager);

        $this->postJson("/api/team/{$this->otherManager->id}/deactivate")->assertNotFound();
        $this->assertTrue($this->otherManager->fresh()->is_active);
    }

    // ── ت٧ — the super admin and assistants stay out ──

    public function test_the_super_admin_cannot_use_the_team_endpoints(): void
    {
        $assistant = $this->assistantOf($this->manager);
        Sanctum::actingAs($this->superAdmin);

        $this->getJson('/api/team')->assertForbidden();
        $this->postJson('/api/team', $this->payload())->assertForbidden();
        $this->putJson("/api/team/{$assistant->id}", ['name' => 'x'])->assertForbidden();
        $this->postJson("/api/team/{$assistant->id}/deactivate")->assertForbidden();
        $this->getJson("/api/team/{$assistant->id}/activity")->assertForbidden();
        $this->getJson('/api/assistant-permissions')->assertForbidden();
    }

    public function test_an_assistant_cannot_manage_a_team(): void
    {
        $assistant = $this->assistantOf($this->manager);
        $colleague = $this->assistantOf($this->manager, ['email' => 'c@example.com']);
        Sanctum::actingAs($assistant);

        $this->getJson('/api/team')->assertForbidden();
        $this->postJson('/api/team', $this->payload())->assertForbidden();
        $this->postJson("/api/team/{$colleague->id}/deactivate")->assertForbidden();
    }

    public function test_the_super_admin_does_not_see_assistants_in_manager_or_user_lists(): void
    {
        $assistant = $this->assistantOf($this->manager);
        Sanctum::actingAs($this->superAdmin);

        $managers = $this->getJson('/api/account-managers')->assertOk()->json('managers');
        $this->assertNotContains($assistant->id, array_column($managers, 'id'));

        $users = $this->getJson('/api/users')->assertOk()->json();
        $this->assertNotContains($assistant->id, array_column($users, 'id'));
    }

    public function test_a_manager_sees_only_their_own_assistants_in_the_users_list(): void
    {
        $mine = $this->assistantOf($this->manager);
        $theirs = $this->assistantOf($this->otherManager, ['email' => 'o@example.com']);
        Sanctum::actingAs($this->manager);

        $ids = array_column($this->getJson('/api/users')->assertOk()->json(), 'id');
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
    }

    public function test_assistant_permission_keys_endpoint(): void
    {
        Sanctum::actingAs($this->manager);

        $this->getJson('/api/assistant-permissions')->assertOk()->assertJson(['permissions' => User::ASSISTANT_PERMISSION_KEYS]);
    }

    // ── ت٨ — deactivating / reactivating the manager ──

    public function test_deactivating_the_manager_deactivates_their_assistants_and_reactivating_restores_only_those(): void
    {
        $solo = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER, 'super_admin_id' => $this->superAdmin->id]);
        $active = $this->assistantOf($solo, ['email' => 'a@example.com']);
        $manuallyOff = $this->assistantOf($solo, ['email' => 'b@example.com', 'is_active' => false, 'deactivated_by_parent' => false]);
        $active->createToken('t');
        $manuallyOff->createToken('t');
        Sanctum::actingAs($this->superAdmin);

        $this->postJson("/api/account-managers/{$solo->id}/deactivate")->assertOk();

        $this->assertFalse($active->fresh()->is_active);
        $this->assertTrue($active->fresh()->deactivated_by_parent);
        $this->assertSame(0, $active->tokens()->count());
        $this->assertSame(0, $manuallyOff->tokens()->count());

        $this->postJson("/api/account-managers/{$solo->id}/activate")->assertOk();

        $this->assertTrue($active->fresh()->is_active);
        $this->assertFalse($active->fresh()->deactivated_by_parent);
        $this->assertFalse($manuallyOff->fresh()->is_active, 'an assistant the manager switched off stays off');
    }

    // ── ت٩ — login follows the manager ──

    public function test_an_assistant_whose_manager_is_inactive_cannot_log_in(): void
    {
        $assistant = $this->assistantOf($this->manager);
        $this->manager->update(['is_active' => false]);

        $this->postJson('/api/auth/login', ['email' => $assistant->email, 'password' => 'Password123'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_an_assistant_cannot_be_activated_while_their_manager_is_inactive(): void
    {
        // the manager himself can't act while inactive, but the model rule is what login uses
        $assistant = $this->assistantOf($this->manager, ['is_active' => true]);
        $this->manager->update(['is_active' => false]);

        $this->assertFalse($assistant->fresh()->isActive());
    }

    // ── ت١٠ — a transferred client follows the new manager's team ──

    public function test_a_transferred_client_moves_from_one_managers_assistants_to_the_others(): void
    {
        $old = $this->assistantOf($this->manager, ['email' => 'old@example.com']);
        $new = $this->assistantOf($this->otherManager, ['email' => 'new@example.com']);
        $client = Client::factory()->create(['manager_id' => $this->manager->id]);
        Workspace::factory()->create(['client_id' => $client->id, 'manager_id' => $this->manager->id]);

        Sanctum::actingAs($old);
        $this->getJson("/api/clients/{$client->id}")->assertOk();

        Sanctum::actingAs($this->superAdmin);
        $this->postJson("/api/clients/{$client->id}/transfer", ['new_manager_id' => $this->otherManager->id])->assertOk();

        Sanctum::actingAs($old);
        $this->getJson("/api/clients/{$client->id}")->assertForbidden();
        $this->getJson('/api/clients')->assertOk()->assertJsonCount(0, 'clients.data');

        Sanctum::actingAs($new);
        $this->getJson("/api/clients/{$client->id}")->assertOk();
    }

    // ── ت١٥ + account deletion ──

    public function test_deleting_a_managers_account_scrubs_their_assistants(): void
    {
        $assistant = $this->assistantOf($this->manager);
        $assistant->createToken('t');
        $this->manager->update(['password' => 'Password123']);
        Sanctum::actingAs($this->manager);

        $this->deleteJson('/api/auth/account', ['password' => 'Password123'])->assertOk();

        $fresh = $assistant->fresh();
        $this->assertSame('Deleted account', $fresh->name);
        $this->assertFalse($fresh->is_active);
        $this->assertSame(0, $fresh->tokens()->count());
        $this->assertStringEndsWith('@deleted.invalid', $fresh->email);
    }

    public function test_an_assistant_can_delete_their_own_account_without_touching_the_manager(): void
    {
        $assistant = $this->assistantOf($this->manager);
        Sanctum::actingAs($assistant);

        $this->deleteJson('/api/auth/account', ['password' => 'Password123'])->assertOk();

        $this->assertSame('Deleted account', $assistant->fresh()->name);
        $this->assertTrue($this->manager->fresh()->is_active);
        $this->assertNotSame('Deleted account', $this->manager->fresh()->name);
    }

    public function test_login_and_me_expose_the_assistants_full_permission_map(): void
    {
        $assistant = $this->assistantOf($this->manager, ['assistant_permissions' => ['can_chat' => true]]);

        $login = $this->postJson('/api/auth/login', ['email' => $assistant->email, 'password' => 'Password123'])->assertOk();
        $login->assertJsonPath('user.role', 'manager_assistant')
            ->assertJsonPath('user.assistant_permissions.can_chat', true)
            ->assertJsonPath('user.assistant_permissions.can_manage_contracts', false)
            ->assertJsonPath('user.assistant_permissions.can_view_clients', true)
            ->assertJsonPath('user.parent_manager_id', $this->manager->id);

        Sanctum::actingAs($assistant);
        $this->getJson('/api/auth/me')->assertOk()
            ->assertJsonPath('user.assistant_permissions.can_view_files', false)
            ->assertJsonPath('user.assistant_permissions.can_view_clients', true);

        Sanctum::actingAs($this->manager);
        $this->getJson('/api/auth/me')->assertOk()->assertJsonMissingPath('user.assistant_permissions');
    }
}
