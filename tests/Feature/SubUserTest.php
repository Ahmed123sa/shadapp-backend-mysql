<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\SubUser;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    // 2. Known holes — these assertions are WRONG on purpose.
    //    Later phases of SUBUSER_PLAN.md must flip each one.
    // ---------------------------------------------------------------

    /**
     * HOLE (plan §2): ten of the eleven permissions are never read by the
     * backend. Only can_respond_approvals is enforced (ApprovalController).
     * The rest hide tabs in the dashboard and the mobile app, so calling the
     * API directly sidesteps them entirely.
     *
     * can_chat is the sample here; can_approve_contracts,
     * can_upload_payment_proof and can_upload_files behave the same way.
     */
    public function test_HOLE_a_sub_user_can_post_chat_without_the_chat_permission(): void
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
            ->assertStatus(201);
    }

    /**
     * The one permission that genuinely is enforced. Kept next to the holes so
     * the contrast is on the record: Phase 2 makes the others look like this.
     */
    public function test_can_respond_approvals_is_actually_enforced(): void
    {
        [$client, $workspace] = $this->makeClient();
        $subUser = SubUser::factory()->create([
            'client_id' => $client->id,
            'permissions' => ['can_respond_approvals' => false],
        ]);
        $approval = \App\Models\Approval::factory()->create(['workspace_id' => $workspace->id]);

        $this->actingAs($subUser, 'sub_user')
            ->postJson("/api/approvals/{$approval->id}/respond", ['action' => 'approved'])
            ->assertStatus(403);
    }

    /**
     * HOLE (plan §3): sub_users has no signature_data column, so
     * ContractController::clientAction() reads null off the model and stores a
     * contract as client_approved with an empty signature. Eloquent returns
     * null for a missing attribute without raising, so nothing surfaces.
     *
     * ApprovalController::respond() already does this correctly by reaching
     * through to the parent client — the same bug was fixed there and missed
     * here.
     *
     * /contracts/{contract}/client-action sits in the auth:sanctum-only group
     * (see TenantIsolationTest::actingAsClientViaToken's comment), not the
     * multi-guard auth.any group — so actingAs($subUser, 'sub_user') would
     * populate the wrong guard and 401 before the controller ever runs. A real
     * bearer token is required here, the same way a real client reaches this
     * route in production.
     */
    public function test_HOLE_a_sub_user_approving_a_contract_stores_no_signature(): void
    {
        [$client, $workspace] = $this->makeClient();
        $client->update(['signature_data' => 'data:image/png;base64,iVBORw0KGgo=']);
        $subUser = SubUser::factory()->create(['client_id' => $client->id]);
        $contract = \App\Models\Contract::factory()->create([
            'workspace_id' => $workspace->id,
            'status' => 'sent',
        ]);

        $token = $subUser->createToken('isolation-test')->plainTextToken;

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson("/api/contracts/{$contract->id}/client-action", ['action' => 'approved'])
            ->assertStatus(200);

        $this->assertSame('client_approved', $contract->fresh()->status);
        $this->assertNull($contract->fresh()->client_signature_data);
    }
}
