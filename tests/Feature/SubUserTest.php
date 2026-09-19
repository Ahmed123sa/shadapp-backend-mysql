<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\SubUser;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Mirrors tests/Feature/SubUserTest.php from shadapp-backend (Postgres)
 * verbatim.
 *
 * Characterization tests for the sub-user feature — Phase 0 of SUBUSER_PLAN.md.
 *
 * A sub-user is an employee of a client company: its own login, sitting inside
 * that company's single workspace, limited by a permissions map. The feature
 * had no test file of its own before this one; the only mentions of SubUser in
 * the suite were incidental lines in TenantIsolationTest and ClientArchiveTest.
 *
 * This file records what the code does *today*, before any fix, so that the
 * later phases have something to break. Two groups live here:
 *
 *   1. Behaviour that is correct and must keep working.
 *   2. Behaviour that is a known hole, marked HOLE below. Those assertions are
 *      deliberately asserting the wrong answer — they exist so the Phase 1
 *      commit has to flip them, which makes the change visible in the diff
 *      rather than silently absent. Do not read them as intended behaviour.
 *
 * The agreed design (18 Sept 2026) is that a client manages its own sub-users
 * and nobody else does — not the account manager, not the super admin.
 */
class SubUserTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Client, 1: Workspace, 2: User}
     */
    private function makeClient(array $overrides = []): array
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $client = Client::factory()->create(array_merge(['manager_id' => $manager->id], $overrides));
        $workspace = Workspace::factory()->create([
            'client_id' => $client->id,
            'manager_id' => $manager->id,
        ]);

        return [$client, $workspace, $manager];
    }

    // ---------------------------------------------------------------
    // 1. Behaviour that is correct today and must survive every phase
    // ---------------------------------------------------------------

    public function test_a_client_can_create_a_sub_user_for_itself(): void
    {
        [$client] = $this->makeClient();

        $response = $this->actingAs($client, 'client')
            ->postJson("/api/clients/{$client->id}/sub-users", [
                'name' => 'Accountant',
                'email' => 'accountant@example.com',
                'password' => 'Password1',
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('sub_users', [
            'email' => 'accountant@example.com',
            'client_id' => $client->id,
        ]);
    }

    public function test_a_new_sub_user_starts_with_no_permissions(): void
    {
        [$client] = $this->makeClient();

        $this->actingAs($client, 'client')
            ->postJson("/api/clients/{$client->id}/sub-users", [
                'name' => 'Accountant',
                'email' => 'accountant@example.com',
                'password' => 'Password1',
            ]);

        // Fails closed: store() writes an empty array, and hasPermission()
        // reads any missing key as false.
        $subUser = SubUser::where('email', 'accountant@example.com')->first();
        $this->assertSame([], $subUser->getPermissionsArray());
        $this->assertFalse($subUser->hasPermission('can_chat'));
    }

    public function test_a_client_can_list_its_own_sub_users(): void
    {
        [$client] = $this->makeClient();
        SubUser::factory()->count(2)->create(['client_id' => $client->id]);

        $response = $this->actingAs($client, 'client')
            ->getJson("/api/clients/{$client->id}/sub-users");

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('sub_users'));
    }

    public function test_a_client_cannot_list_another_clients_sub_users(): void
    {
        [$clientA] = $this->makeClient();
        [$clientB] = $this->makeClient();
        SubUser::factory()->create(['client_id' => $clientB->id]);

        $this->actingAs($clientA, 'client')
            ->getJson("/api/clients/{$clientB->id}/sub-users")
            ->assertStatus(403);
    }

    public function test_a_client_can_change_its_own_sub_users_permissions(): void
    {
        [$client] = $this->makeClient();
        $subUser = SubUser::factory()->create(['client_id' => $client->id]);

        $response = $this->actingAs($client, 'client')
            ->patchJson("/api/sub-users/{$subUser->id}/permissions", [
                'permissions' => ['can_chat' => true, 'can_view_files' => false],
            ]);

        $response->assertStatus(200);
        $this->assertTrue($subUser->fresh()->hasPermission('can_chat'));
        $this->assertFalse($subUser->fresh()->hasPermission('can_view_files'));
    }

    public function test_unknown_permission_keys_are_discarded(): void
    {
        [$client] = $this->makeClient();
        $subUser = SubUser::factory()->create(['client_id' => $client->id]);

        $this->actingAs($client, 'client')
            ->patchJson("/api/sub-users/{$subUser->id}/permissions", [
                'permissions' => ['can_chat' => true, 'can_delete_everything' => true],
            ]);

        // updatePermissions() whitelists the 11 known keys, so an invented one
        // never reaches the column.
        $this->assertArrayNotHasKey('can_delete_everything', $subUser->fresh()->getPermissionsArray());
    }

    public function test_a_client_cannot_touch_another_clients_sub_user(): void
    {
        [$clientA] = $this->makeClient();
        [$clientB] = $this->makeClient();
        $subUser = SubUser::factory()->create(['client_id' => $clientB->id]);

        $this->actingAs($clientA, 'client')
            ->patchJson("/api/sub-users/{$subUser->id}/permissions", ['permissions' => ['can_chat' => true]])
            ->assertStatus(403);

        $this->actingAs($clientA, 'client')
            ->deleteJson("/api/sub-users/{$subUser->id}")
            ->assertStatus(403);
    }

    public function test_a_client_can_delete_its_own_sub_user(): void
    {
        [$client] = $this->makeClient();
        $subUser = SubUser::factory()->create(['client_id' => $client->id]);

        $this->actingAs($client, 'client')
            ->deleteJson("/api/sub-users/{$subUser->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('sub_users', ['id' => $subUser->id]);
    }

    public function test_a_sub_user_can_read_its_own_profile(): void
    {
        [$client] = $this->makeClient();
        $subUser = SubUser::factory()->create(['client_id' => $client->id]);

        $this->actingAs($subUser, 'sub_user')
            ->getJson("/api/sub-users/{$subUser->id}")
            ->assertStatus(200)
            ->assertJsonPath('sub_user.id', $subUser->id);
    }

    public function test_a_sub_user_cannot_read_a_colleagues_profile(): void
    {
        [$client] = $this->makeClient();
        $subUser = SubUser::factory()->create(['client_id' => $client->id]);
        $colleague = SubUser::factory()->create(['client_id' => $client->id]);

        $this->actingAs($subUser, 'sub_user')
            ->getJson("/api/sub-users/{$colleague->id}")
            ->assertStatus(403);
    }

    public function test_a_sub_user_cannot_create_another_sub_user(): void
    {
        [$client] = $this->makeClient();
        $subUser = SubUser::factory()->create(['client_id' => $client->id]);

        $this->actingAs($subUser, 'sub_user')
            ->postJson("/api/clients/{$client->id}/sub-users", [
                'name' => 'Smuggled',
                'email' => 'smuggled@example.com',
                'password' => 'Password1',
            ])
            ->assertStatus(403);
    }

    public function test_a_sub_user_logs_in_through_the_client_login_route(): void
    {
        [$client] = $this->makeClient();
        $subUser = SubUser::factory()->create([
            'client_id' => $client->id,
            'password' => 'Password1',
        ]);

        $this->postJson('/api/auth/client/login', [
            'email' => $subUser->email,
            'password' => 'Password1',
        ])
            ->assertStatus(200)
            ->assertJsonPath('login_type', 'sub_user')
            ->assertJsonPath('sub_user.id', $subUser->id)
            ->assertJsonPath('client.id', $client->id);
    }

    public function test_a_sub_user_of_an_archived_client_cannot_log_in_through_the_client_route(): void
    {
        [$client] = $this->makeClient(['status' => 'archived']);
        $subUser = SubUser::factory()->create([
            'client_id' => $client->id,
            'password' => 'Password1',
        ]);

        $this->postJson('/api/auth/client/login', [
            'email' => $subUser->email,
            'password' => 'Password1',
        ])->assertStatus(422);
    }

    // ---------------------------------------------------------------
    // 1b. Phase 1 fixes (SUBUSER_PLAN.md §1) — these four used to be
    //     test_HOLE_* asserting the wrong answer; now they assert the fix.
    // ---------------------------------------------------------------

    /**
     * Fixed in Phase 1 §1.1: SubUserController::store() now calls
     * authorize('create', [SubUser::class, $client]) with the target client,
     * and SubUserPolicy::create() checks $user->id === $client->id. The route
     * still has no {workspace} segment, so this policy check is the only
     * thing standing between one client and another client's tenant.
     */
    public function test_a_client_cannot_create_a_sub_user_under_another_client(): void
    {
        [$clientA] = $this->makeClient();
        [$clientB] = $this->makeClient();

        $this->actingAs($clientA, 'client')
            ->postJson("/api/clients/{$clientB->id}/sub-users", [
                'name' => 'Planted',
                'email' => 'planted@example.com',
                'password' => 'Password1',
            ])
            ->assertStatus(403);

        $this->assertDatabaseMissing('sub_users', ['email' => 'planted@example.com']);
    }

    /**
     * Fixed in Phase 1 §1.2: the User branch was deleted from
     * SubUserPolicy::create() rather than narrowed — the agreed design gives
     * the account manager no role in sub-user management at all.
     */
    public function test_an_account_manager_cannot_create_a_sub_user(): void
    {
        [$client] = $this->makeClient();
        $otherManager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);

        $this->actingAs($otherManager, 'sanctum')
            ->postJson("/api/clients/{$client->id}/sub-users", [
                'name' => 'By manager',
                'email' => 'bymanager@example.com',
                'password' => 'Password1',
            ])
            ->assertStatus(403);
    }

    /**
     * Fixed in Phase 1 §1.2: SubUserPolicy::view() no longer has a User
     * branch, so an account manager gets the same 403 Gate::denies() throws
     * for any unrecognised principal.
     */
    public function test_no_manager_can_read_a_sub_user(): void
    {
        [$client] = $this->makeClient();
        $subUser = SubUser::factory()->create(['client_id' => $client->id]);
        $unrelatedManager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);

        $this->actingAs($unrelatedManager, 'sanctum')
            ->getJson("/api/sub-users/{$subUser->id}")
            ->assertStatus(403);
    }

    /**
     * Fixed in Phase 1 §1.2: same for updateProfile().
     */
    public function test_no_manager_can_change_a_sub_users_email(): void
    {
        [$client] = $this->makeClient();
        $subUser = SubUser::factory()->create(['client_id' => $client->id]);
        $unrelatedManager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);

        $this->actingAs($unrelatedManager, 'sanctum')
            ->putJson("/api/sub-users/{$subUser->id}/profile", ['email' => 'hijacked@example.com'])
            ->assertStatus(403);

        $this->assertNotSame('hijacked@example.com', $subUser->fresh()->email);
    }

    /**
     * Fixed in Phase 1 §1.2: ClientController::subUsers() now rejects any
     * User principal outright, so an account manager cannot list a client's
     * sub-users either — even one they manage.
     */
    public function test_no_manager_can_list_a_clients_sub_users(): void
    {
        [$client, , $manager] = $this->makeClient();
        SubUser::factory()->create(['client_id' => $client->id]);

        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/clients/{$client->id}/sub-users")
            ->assertStatus(403);
    }

    /**
     * Fixed in Phase 1 §1.3: /auth/sub-user/login and its controller method
     * are gone. Sub-users log in through /auth/client/login only, which does
     * check archiving (see test_a_sub_user_of_an_archived_client_cannot_log_in
     * _through_the_client_route above).
     */
    public function test_the_orphan_login_route_no_longer_exists(): void
    {
        [$client] = $this->makeClient();
        $subUser = SubUser::factory()->create([
            'client_id' => $client->id,
            'password' => 'Password1',
        ]);

        $this->postJson('/api/auth/sub-user/login', [
            'email' => $subUser->email,
            'password' => 'Password1',
        ])->assertStatus(404);
    }

    // ---------------------------------------------------------------
    // 2. Phase 2 fixes (SUBUSER_PLAN.md §2) — server-side action-permission
    //    guards. Before this phase only can_respond_approvals was enforced
    //    (a manual check inside ApprovalController); the other four
    //    permissions only hid a tab in the dashboard/mobile app, so calling
    //    the API directly sidestepped them entirely. The route-level
    //    subuser.can:<permission> middleware (RequireSubUserPermission) now
    //    enforces all five. Each guarded route gets a "denied when the flag
    //    is false" test and an "allowed when the flag is true" test, plus one
    //    sanity check that the primary Client account is never touched by
    //    this middleware.
    // ---------------------------------------------------------------

    public function test_a_sub_user_cannot_post_chat_without_the_chat_permission(): void
    {
        [$client, $workspace] = $this->makeClient();
        $subUser = SubUser::factory()->create([
            'client_id' => $client->id,
            'permissions' => ['can_chat' => false],
        ]);

        $this->actingAs($subUser, 'sub_user')
            ->postJson("/api/workspaces/{$workspace->id}/chat", [
                'message' => 'sent with the permission switched off',
                'type' => 'text',
            ])
            ->assertStatus(403);
    }

    public function test_a_sub_user_can_post_chat_with_the_chat_permission(): void
    {
        [$client, $workspace] = $this->makeClient();
        $subUser = SubUser::factory()->create([
            'client_id' => $client->id,
            'permissions' => ['can_chat' => true],
        ]);

        $this->actingAs($subUser, 'sub_user')
            ->postJson("/api/workspaces/{$workspace->id}/chat", [
                'message' => 'sent with the permission switched on',
                'type' => 'text',
            ])
            ->assertStatus(201);
    }

    public function test_a_sub_user_cannot_upload_a_payment_proof_without_the_permission(): void
    {
        [$client, $workspace] = $this->makeClient();
        $subUser = SubUser::factory()->create([
            'client_id' => $client->id,
            'permissions' => ['can_upload_payment_proof' => false],
        ]);

        $this->actingAs($subUser, 'sub_user')
            ->postJson("/api/workspaces/{$workspace->id}/payments", [
                'amount' => 1000,
                'method_type' => 'bank_transfer',
            ])
            ->assertStatus(403);
    }

    public function test_a_sub_user_can_upload_a_payment_proof_with_the_permission(): void
    {
        [$client, $workspace] = $this->makeClient();
        $subUser = SubUser::factory()->create([
            'client_id' => $client->id,
            'permissions' => ['can_upload_payment_proof' => true],
        ]);

        $this->actingAs($subUser, 'sub_user')
            ->postJson("/api/workspaces/{$workspace->id}/payments", [
                'amount' => 1000,
                'method_type' => 'bank_transfer',
            ])
            ->assertStatus(201);
    }

    public function test_a_sub_user_cannot_update_a_payment_without_the_permission(): void
    {
        [$client, $workspace] = $this->makeClient();
        $payment = \App\Models\Payment::factory()->create([
            'workspace_id' => $workspace->id,
            'client_id' => $client->id,
        ]);
        $subUser = SubUser::factory()->create([
            'client_id' => $client->id,
            'permissions' => ['can_upload_payment_proof' => false],
        ]);

        $this->actingAs($subUser, 'sub_user')
            ->putJson("/api/workspaces/{$workspace->id}/payments/{$payment->id}", [
                'amount' => 2000,
            ])
            ->assertStatus(403);
    }

    public function test_a_sub_user_can_update_a_payment_with_the_permission(): void
    {
        [$client, $workspace] = $this->makeClient();
        $payment = \App\Models\Payment::factory()->create([
            'workspace_id' => $workspace->id,
            'client_id' => $client->id,
        ]);
        $subUser = SubUser::factory()->create([
            'client_id' => $client->id,
            'permissions' => ['can_upload_payment_proof' => true],
        ]);

        $this->actingAs($subUser, 'sub_user')
            ->putJson("/api/workspaces/{$workspace->id}/payments/{$payment->id}", [
                'amount' => 2000,
            ])
            ->assertStatus(200);
    }

    public function test_a_sub_user_cannot_upload_a_file_without_the_permission(): void
    {
        Storage::fake('public');
        [$client, $workspace] = $this->makeClient();
        $subUser = SubUser::factory()->create([
            'client_id' => $client->id,
            'permissions' => ['can_upload_files' => false],
        ]);

        $this->actingAs($subUser, 'sub_user')
            ->postJson("/api/workspaces/{$workspace->id}/files", [
                'file' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(403);
    }

    public function test_a_sub_user_can_upload_a_file_with_the_permission(): void
    {
        Storage::fake('public');
        [$client, $workspace] = $this->makeClient();
        $subUser = SubUser::factory()->create([
            'client_id' => $client->id,
            'permissions' => ['can_upload_files' => true],
        ]);

        $this->actingAs($subUser, 'sub_user')
            ->postJson("/api/workspaces/{$workspace->id}/files", [
                'file' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(201);
    }

    /**
     * can_respond_approvals originally guarded /approvals/{approval}/respond
     * (ApprovalController::respond) in this phase's first pass. That turned
     * out to be the wrong route: ApprovalPolicy::respond() already restricts
     * that action to staff (User) only, so a Client or SubUser can never
     * reach it regardless of any permission flag — the manual permission
     * check that used to live in that controller method was dead code.
     *
     * The route a client/sub-user actually uses to approve or request edits
     * is /chat/{chatMessage}/respond (ChatController::respond), which also
     * updates the linked Approval when one exists. That route had no
     * can_respond_approvals guard at all before this fix — the real hole.
     * The permission now lives on this route instead.
     */
    public function test_a_sub_user_cannot_respond_to_a_chat_message_without_the_permission(): void
    {
        [$client, $workspace, $manager] = $this->makeClient();
        $subUser = SubUser::factory()->create([
            'client_id' => $client->id,
            'permissions' => ['can_respond_approvals' => false],
        ]);
        $chatMessage = \App\Models\ChatMessage::create([
            'workspace_id' => $workspace->id,
            'sender_type' => User::class,
            'sender_id' => $manager->id,
            'message' => 'needs your approval',
            'type' => 'text',
            'requires_action' => true,
            'action_taken' => false,
        ]);

        $this->actingAs($subUser, 'sub_user')
            ->postJson("/api/chat/{$chatMessage->id}/respond", ['action' => 'approved'])
            ->assertStatus(403);
    }

    public function test_a_sub_user_can_respond_to_a_chat_message_with_the_permission(): void
    {
        [$client, $workspace, $manager] = $this->makeClient();
        $subUser = SubUser::factory()->create([
            'client_id' => $client->id,
            'permissions' => ['can_respond_approvals' => true],
        ]);
        $chatMessage = \App\Models\ChatMessage::create([
            'workspace_id' => $workspace->id,
            'sender_type' => User::class,
            'sender_id' => $manager->id,
            'message' => 'needs your approval',
            'type' => 'text',
            'requires_action' => true,
            'action_taken' => false,
        ]);

        $this->actingAs($subUser, 'sub_user')
            ->postJson("/api/chat/{$chatMessage->id}/respond", ['action' => 'approved'])
            ->assertStatus(200);
    }

    /**
     * /contracts/{contract}/client-action sits in the auth:sanctum-only group
     * (see the signature-bug test below), not the multi-guard auth.any group
     * — so a real bearer token is required, the same way the signature-bug
     * test already reaches this route.
     */
    public function test_a_sub_user_cannot_take_a_contract_client_action_without_the_permission(): void
    {
        [$client, $workspace] = $this->makeClient();
        $subUser = SubUser::factory()->create([
            'client_id' => $client->id,
            'permissions' => ['can_approve_contracts' => false],
        ]);
        $contract = \App\Models\Contract::factory()->create([
            'workspace_id' => $workspace->id,
            'status' => 'sent',
        ]);
        $token = $subUser->createToken('permission-test')->plainTextToken;

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson("/api/contracts/{$contract->id}/client-action", ['action' => 'approved'])
            ->assertStatus(403);
    }

    /**
     * Sanity check for the middleware itself: RequireSubUserPermission only
     * ever restricts a SubUser instance. The primary Client account has no
     * permissions map at all and must never be blocked by this guard.
     */
    public function test_a_client_is_never_restricted_by_any_subuser_permission_guard(): void
    {
        [$client, $workspace] = $this->makeClient();

        $this->actingAs($client, 'client')
            ->postJson("/api/workspaces/{$workspace->id}/chat", [
                'message' => 'the primary client account is never gated by subuser.can',
                'type' => 'text',
            ])
            ->assertStatus(201);
    }

    /**
     * Fixed in Phase 3 (plan §3): sub_users has no signature_data column, so
     * ContractController::clientAction() used to read null off the SubUser
     * model and store the contract as client_approved with an empty
     * signature — Eloquent returns null for a missing attribute without
     * raising, so nothing surfaced. The fix reaches through to the parent
     * client's signature when the signer is a SubUser, the same way
     * ApprovalController::respond() already did.
     *
     * can_approve_contracts is granted explicitly here because Phase 2 added
     * a route guard on that same permission — without it this test would be
     * stopped at 403 before it ever reached the signature logic it's about.
     *
     * /contracts/{contract}/client-action sits in the auth:sanctum-only group
     * (see TenantIsolationTest::actingAsClientViaToken's comment), not the
     * multi-guard auth.any group — so actingAs($subUser, 'sub_user') would
     * populate the wrong guard and 401 before the controller ever runs. A real
     * bearer token is required here, the same way a real client reaches this
     * route in production.
     */
    public function test_a_sub_user_approving_a_contract_stores_the_parent_clients_signature(): void
    {
        [$client, $workspace] = $this->makeClient();
        $client->update(['signature_data' => 'data:image/png;base64,iVBORw0KGgo=']);
        $subUser = SubUser::factory()->create([
            'client_id' => $client->id,
            'permissions' => ['can_approve_contracts' => true],
        ]);
        $contract = \App\Models\Contract::factory()->create([
            'workspace_id' => $workspace->id,
            'status' => 'sent',
        ]);

        $token = $subUser->createToken('isolation-test')->plainTextToken;

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson("/api/contracts/{$contract->id}/client-action", ['action' => 'approved'])
            ->assertStatus(200);

        $this->assertSame('client_approved', $contract->fresh()->status);
        $this->assertSame($client->signature_data, $contract->fresh()->client_signature_data);
    }

    /**
     * The primary Client account keeps signing with its own signature —
     * unaffected by the sub-user fallback above.
     */
    public function test_a_client_approving_a_contract_still_stores_its_own_signature(): void
    {
        [$client, $workspace] = $this->makeClient();
        $client->update(['signature_data' => 'data:image/png;base64,iVBORw0KGgo=']);
        $contract = \App\Models\Contract::factory()->create([
            'workspace_id' => $workspace->id,
            'status' => 'sent',
        ]);

        $token = $client->createToken('isolation-test')->plainTextToken;

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson("/api/contracts/{$contract->id}/client-action", ['action' => 'approved'])
            ->assertStatus(200);

        $this->assertSame($client->signature_data, $contract->fresh()->client_signature_data);
    }

    // ---------------------------------------------------------------
    // 4. Phase 4 (SUBUSER_PLAN.md §4.1) — password change.
    //
    // Deactivation (the original §4.2/§4.3 draft) was dropped: the product
    // decision is that permanent deletion (already covered above by
    // test_a_client_can_delete_its_own_sub_user) is the only lifecycle exit
    // for a sub-user — no deactivate/reactivate pair. Token revocation on
    // password change is kept, since it's good practice independent of
    // deactivation.
    // ---------------------------------------------------------------

    public function test_a_client_can_change_a_sub_users_password_without_the_old_one(): void
    {
        [$client] = $this->makeClient();
        $subUser = SubUser::factory()->create([
            'client_id' => $client->id,
            'password' => 'OldPassword1',
        ]);

        $this->actingAs($client, 'client')
            ->patchJson("/api/sub-users/{$subUser->id}/password", [
                'password' => 'NewPassword2',
            ])
            ->assertStatus(200);

        $this->assertTrue(Hash::check('NewPassword2', $subUser->fresh()->password));
    }

    public function test_a_client_cannot_change_another_clients_sub_users_password(): void
    {
        [$clientA] = $this->makeClient();
        [$clientB] = $this->makeClient();
        $subUser = SubUser::factory()->create(['client_id' => $clientB->id]);

        $this->actingAs($clientA, 'client')
            ->patchJson("/api/sub-users/{$subUser->id}/password", [
                'password' => 'NewPassword2',
            ])
            ->assertStatus(403);
    }

    public function test_a_sub_user_can_change_their_own_password_with_the_current_one(): void
    {
        [$client] = $this->makeClient();
        $subUser = SubUser::factory()->create([
            'client_id' => $client->id,
            'password' => 'OldPassword1',
        ]);

        $this->actingAs($subUser, 'sub_user')
            ->patchJson("/api/sub-users/{$subUser->id}/password", [
                'current_password' => 'OldPassword1',
                'password' => 'NewPassword2',
            ])
            ->assertStatus(200);

        $this->assertTrue(Hash::check('NewPassword2', $subUser->fresh()->password));
    }

    public function test_a_sub_user_cannot_change_their_own_password_with_the_wrong_current_one(): void
    {
        [$client] = $this->makeClient();
        $subUser = SubUser::factory()->create([
            'client_id' => $client->id,
            'password' => 'OldPassword1',
        ]);

        $this->actingAs($subUser, 'sub_user')
            ->patchJson("/api/sub-users/{$subUser->id}/password", [
                'current_password' => 'WrongPassword9',
                'password' => 'NewPassword2',
            ])
            ->assertStatus(422);

        $this->assertTrue(Hash::check('OldPassword1', $subUser->fresh()->password));
    }

    public function test_a_sub_user_cannot_change_their_own_password_without_supplying_the_current_one(): void
    {
        [$client] = $this->makeClient();
        $subUser = SubUser::factory()->create([
            'client_id' => $client->id,
            'password' => 'OldPassword1',
        ]);

        $this->actingAs($subUser, 'sub_user')
            ->patchJson("/api/sub-users/{$subUser->id}/password", [
                'password' => 'NewPassword2',
            ])
            ->assertStatus(422);
    }

    public function test_changing_a_sub_users_password_revokes_their_existing_tokens(): void
    {
        [$client] = $this->makeClient();
        $subUser = SubUser::factory()->create([
            'client_id' => $client->id,
            'password' => 'OldPassword1',
        ]);
        $oldToken = $subUser->createToken('old-session')->plainTextToken;
        $clientToken = $client->createToken('isolation-test')->plainTextToken;

        $this->withHeaders(['Authorization' => 'Bearer ' . $clientToken])
            ->patchJson("/api/sub-users/{$subUser->id}/password", [
                'password' => 'NewPassword2',
            ])
            ->assertStatus(200);

        $this->assertSame(0, $subUser->tokens()->count());

        // The sanctum guard resolved on the request above is cached by the
        // AuthManager and would otherwise keep authenticating as the client
        // from that request instead of re-evaluating this new bearer token
        // (see TenantIsolationTest::actingAsClientViaToken's comment).
        $this->app->make('auth')->forgetGuards();

        $this->withHeaders(['Authorization' => 'Bearer ' . $oldToken])
            ->getJson("/api/sub-users/{$subUser->id}")
            ->assertStatus(401);
    }

    // ---------------------------------------------------------------
    // 5. Phase 5 (SUBUSER_PLAN.md §5.1/§5.2/§5.4) — small leaks.
    // ---------------------------------------------------------------

    public function test_badge_counts_are_zeroed_for_tabs_a_sub_user_cannot_view(): void
    {
        [$client, $workspace] = $this->makeClient();
        $subUser = SubUser::factory()->create([
            'client_id' => $client->id,
            'permissions' => ['can_chat' => true], // no can_view_payments etc.
        ]);
        \App\Models\Payment::factory()->create(['workspace_id' => $workspace->id, 'client_id' => $client->id, 'status' => 'pending']);
        \App\Models\Contract::factory()->create(['workspace_id' => $workspace->id, 'status' => 'sent']);
        \App\Models\Approval::factory()->create(['workspace_id' => $workspace->id, 'status' => 'pending']);
        \App\Models\FileEntry::create([
            'workspace_id' => $workspace->id,
            'uploaded_by_type' => Client::class,
            'uploaded_by_id' => $client->id,
            'file_url' => '/storage/x.pdf',
            'name' => 'x.pdf',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($subUser, 'sub_user')->getJson('/api/badge-counts');

        $response->assertStatus(200);
        $this->assertSame(0, $response->json('contracts'));
        $this->assertSame(0, $response->json('approvals'));
        $this->assertSame(0, $response->json('payments'));
        $this->assertSame(0, $response->json('files'));
    }

    public function test_badge_counts_still_show_for_tabs_a_sub_user_can_view(): void
    {
        [$client, $workspace] = $this->makeClient();
        $subUser = SubUser::factory()->create([
            'client_id' => $client->id,
            'permissions' => ['can_view_payments' => true],
        ]);
        \App\Models\Payment::factory()->create(['workspace_id' => $workspace->id, 'client_id' => $client->id, 'status' => 'pending']);

        $this->actingAs($subUser, 'sub_user')
            ->getJson('/api/badge-counts')
            ->assertStatus(200)
            ->assertJsonPath('payments', 1);
    }

    public function test_badge_counts_for_the_primary_client_are_never_restricted_by_permissions(): void
    {
        [$client, $workspace] = $this->makeClient();
        \App\Models\Payment::factory()->create(['workspace_id' => $workspace->id, 'client_id' => $client->id, 'status' => 'pending']);

        $this->actingAs($client, 'client')
            ->getJson('/api/badge-counts')
            ->assertStatus(200)
            ->assertJsonPath('payments', 1);
    }

    public function test_a_sub_user_cannot_list_its_own_colleagues(): void
    {
        [$client] = $this->makeClient();
        $subUser = SubUser::factory()->create(['client_id' => $client->id]);
        SubUser::factory()->create(['client_id' => $client->id]);

        $this->actingAs($subUser, 'sub_user')
            ->getJson("/api/clients/{$client->id}/sub-users")
            ->assertStatus(403);
    }

    public function test_updating_a_sub_users_profile_writes_an_audit_log_entry(): void
    {
        [$client] = $this->makeClient();
        $subUser = SubUser::factory()->create(['client_id' => $client->id, 'name' => 'Old Name']);

        $this->actingAs($client, 'client')
            ->putJson("/api/sub-users/{$subUser->id}/profile", ['name' => 'New Name'])
            ->assertStatus(200);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => SubUser::class,
            'auditable_id' => $subUser->id,
            'action' => 'sub_user.profile_updated',
        ]);
    }

    // ---------------------------------------------------------------
    // 6. Phase 6 (SUBUSER_PLAN.md §6.1/§6.2/§6.3) — tidiness.
    // ---------------------------------------------------------------

    public function test_permission_keys_endpoint_returns_the_single_source_list(): void
    {
        [$client] = $this->makeClient();

        $this->actingAs($client, 'client')
            ->getJson('/api/sub-user-permissions')
            ->assertStatus(200)
            ->assertJson(['permissions' => SubUser::PERMISSION_KEYS]);
    }

    public function test_creating_a_sub_user_returns_the_shaped_response_not_the_raw_model(): void
    {
        [$client] = $this->makeClient();

        $response = $this->actingAs($client, 'client')
            ->postJson("/api/clients/{$client->id}/sub-users", [
                'name' => 'Accountant',
                'email' => 'shaped@example.com',
                'password' => 'Password1',
            ]);

        $response->assertStatus(201)->assertJsonStructure([
            'sub_user' => ['id', 'name', 'email', 'phone', 'date_of_birth', 'permissions', 'avatar_url', 'client_id'],
        ]);
        // The raw model would have serialized created_at/updated_at and the
        // (hidden) password hash alongside these — present() returns only
        // the fields above, same shape as show()/updatePermissions().
        $response->assertJsonMissingPath('sub_user.created_at');
    }

    public function test_updating_permissions_returns_the_full_shaped_sub_user_not_just_id_and_permissions(): void
    {
        [$client] = $this->makeClient();
        $subUser = SubUser::factory()->create(['client_id' => $client->id, 'name' => 'Employee']);

        $this->actingAs($client, 'client')
            ->patchJson("/api/sub-users/{$subUser->id}/permissions", ['permissions' => ['can_chat' => true]])
            ->assertStatus(200)
            ->assertJsonPath('sub_user.name', 'Employee')
            ->assertJsonPath('sub_user.permissions.can_chat', true);
    }

    // ---------------------------------------------------------------
    // 7. Transaction safety net (18 Sept 2026) — closes a bug report:
    // creating/editing a sub-user showed an error in the UI, but a refresh
    // revealed it had actually gone through. Every write below is followed
    // by an AuditLog::create() call with no transaction around the pair, so
    // an AuditLog failure left the first write committed while the client
    // still got a 500. These tests force that failure via a `creating`
    // model event and assert the whole request rolls back instead.
    // ---------------------------------------------------------------

    public function test_a_failing_audit_log_rolls_back_a_new_sub_user(): void
    {
        [$client] = $this->makeClient();

        AuditLog::creating(function () {
            throw new \RuntimeException('forced failure for test');
        });

        try {
            $this->actingAs($client, 'client')
                ->postJson("/api/clients/{$client->id}/sub-users", [
                    'name' => 'Accountant',
                    'email' => 'rollback-create@example.com',
                    'password' => 'Password1',
                ])
                ->assertStatus(500);

            $this->assertDatabaseMissing('sub_users', ['email' => 'rollback-create@example.com']);
        } finally {
            AuditLog::flushEventListeners();
        }
    }

    public function test_a_failing_audit_log_rolls_back_a_permissions_update(): void
    {
        [$client] = $this->makeClient();
        $subUser = SubUser::factory()->create(['client_id' => $client->id, 'permissions' => ['can_chat' => false]]);

        AuditLog::creating(function () {
            throw new \RuntimeException('forced failure for test');
        });

        try {
            $this->actingAs($client, 'client')
                ->patchJson("/api/sub-users/{$subUser->id}/permissions", ['permissions' => ['can_chat' => true]])
                ->assertStatus(500);

            $this->assertFalse($subUser->fresh()->hasPermission('can_chat'));
        } finally {
            AuditLog::flushEventListeners();
        }
    }

    public function test_a_failing_audit_log_rolls_back_a_sub_user_deletion(): void
    {
        [$client] = $this->makeClient();
        $subUser = SubUser::factory()->create(['client_id' => $client->id]);

        AuditLog::creating(function () {
            throw new \RuntimeException('forced failure for test');
        });

        try {
            $this->actingAs($client, 'client')
                ->deleteJson("/api/sub-users/{$subUser->id}")
                ->assertStatus(500);

            $this->assertDatabaseHas('sub_users', ['id' => $subUser->id]);
        } finally {
            AuditLog::flushEventListeners();
        }
    }

    public function test_a_failing_audit_log_rolls_back_a_profile_update(): void
    {
        [$client] = $this->makeClient();
        $subUser = SubUser::factory()->create(['client_id' => $client->id, 'name' => 'Original Name']);

        AuditLog::creating(function () {
            throw new \RuntimeException('forced failure for test');
        });

        try {
            $this->actingAs($client, 'client')
                ->putJson("/api/sub-users/{$subUser->id}/profile", ['name' => 'New Name'])
                ->assertStatus(500);

            $this->assertSame('Original Name', $subUser->fresh()->name);
        } finally {
            AuditLog::flushEventListeners();
        }
    }

    public function test_a_failing_audit_log_rolls_back_a_password_change(): void
    {
        [$client] = $this->makeClient();
        $subUser = SubUser::factory()->create(['client_id' => $client->id, 'password' => 'OldPassword1']);
        $subUser->createToken('old-session');

        AuditLog::creating(function () {
            throw new \RuntimeException('forced failure for test');
        });

        try {
            $this->actingAs($client, 'client')
                ->patchJson("/api/sub-users/{$subUser->id}/password", ['password' => 'NewPassword2'])
                ->assertStatus(500);

            $this->assertTrue(Hash::check('OldPassword1', $subUser->fresh()->password));
            $this->assertDatabaseHas('personal_access_tokens', [
                'tokenable_id' => $subUser->id,
                'tokenable_type' => SubUser::class,
            ]);
        } finally {
            AuditLog::flushEventListeners();
        }
    }
}
