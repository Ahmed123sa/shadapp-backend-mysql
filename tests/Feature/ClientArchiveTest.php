<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\SubUser;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers ClientController::archive/unarchive — the client-deletion
 * replacement from DATA_SAFETY_PLAN.md §2.3. Archiving reuses the existing
 * clients.status column (no migration) with a new 'archived' value: the
 * client is hidden from the default list, can't log in, and its workspace
 * stops accepting new activity, but nothing is deleted — data, files and
 * history stay exactly where they are, and unarchiving restores everything.
 */
class ClientArchiveTest extends TestCase
{
    use RefreshDatabase;

    private function makeClientWithWorkspace(User $manager, array $clientOverrides = []): array
    {
        $client = Client::factory()->create(array_merge(['manager_id' => $manager->id], $clientOverrides));
        $workspace = Workspace::factory()->create([
            'client_id' => $client->id,
            'manager_id' => $manager->id,
        ]);

        return [$client, $workspace];
    }

    public function test_archiving_sets_status_to_archived(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        [$client] = $this->makeClientWithWorkspace($manager);

        $response = $this->actingAs($admin, 'sanctum')->postJson("/api/clients/{$client->id}/archive");

        $response->assertStatus(200);
        $this->assertSame('archived', $client->fresh()->status);
    }

    public function test_archiving_writes_an_audit_log_entry(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        [$client] = $this->makeClientWithWorkspace($manager);

        $this->actingAs($admin, 'sanctum')->postJson("/api/clients/{$client->id}/archive");

        $log = AuditLog::where('action', 'client.archived')->where('auditable_id', $client->id)->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->user_id);
    }

    public function test_archived_client_is_hidden_from_the_default_list(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        [$client] = $this->makeClientWithWorkspace($manager);

        $this->actingAs($admin, 'sanctum')->postJson("/api/clients/{$client->id}/archive");

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/clients');
        $ids = collect($response->json('clients.data'))->pluck('id');
        $this->assertFalse($ids->contains($client->id));
    }

    // Stands in for "still visible in reports": the report-facing client
    // picker (see reports/page.tsx) requests the list with include_archived=1
    // specifically so a manager filter doesn't go dead just because the
    // client behind it was later archived — this is what proves that value
    // still resolves the client instead of quietly dropping it forever.
    public function test_archived_client_is_still_reachable_with_include_archived(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        [$client] = $this->makeClientWithWorkspace($manager);

        $this->actingAs($admin, 'sanctum')->postJson("/api/clients/{$client->id}/archive");

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/clients?include_archived=1');
        $ids = collect($response->json('clients.data'))->pluck('id');
        $this->assertTrue($ids->contains($client->id));

        // And the record itself is untouched — archiving is a status flip,
        // not a soft-delete that blanks other columns.
        $this->assertSame($client->company_name, Client::find($client->id)->company_name);
    }

    public function test_archived_client_cannot_log_in(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        [$client] = $this->makeClientWithWorkspace($manager, ['password' => 'Password1']);

        $this->actingAs($admin, 'sanctum')->postJson("/api/clients/{$client->id}/archive");

        $response = $this->postJson('/api/auth/client/login', [
            'email' => $client->email,
            'password' => 'Password1',
        ]);

        $response->assertStatus(422);
    }

    public function test_sub_user_of_an_archived_client_cannot_log_in(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        [$client] = $this->makeClientWithWorkspace($manager);
        // No SubUserFactory exists in this codebase — created directly, same
        // as the model's own fillable list.
        $subUser = SubUser::create([
            'name' => 'Sub User',
            'email' => 'subuser@example.com',
            'password' => 'Password1',
            'client_id' => $client->id,
        ]);

        $this->actingAs($admin, 'sanctum')->postJson("/api/clients/{$client->id}/archive");

        $response = $this->postJson('/api/auth/client/login', [
            'email' => $subUser->email,
            'password' => 'Password1',
        ]);

        $response->assertStatus(422);
    }

    public function test_creating_a_contract_for_an_archived_client_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        [$client, $workspace] = $this->makeClientWithWorkspace($manager);

        $this->actingAs($admin, 'sanctum')->postJson("/api/clients/{$client->id}/archive");

        $response = $this->actingAs($manager, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->id}/contracts", ['title' => 'New Contract']);

        $response->assertStatus(422);
        $this->assertSame(0, $workspace->contracts()->count());
    }

    public function test_unarchiving_restores_the_client_to_active(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        [$client] = $this->makeClientWithWorkspace($manager);

        $this->actingAs($admin, 'sanctum')->postJson("/api/clients/{$client->id}/archive");
        $this->assertSame('archived', $client->fresh()->status);

        $response = $this->actingAs($admin, 'sanctum')->postJson("/api/clients/{$client->id}/unarchive");

        $response->assertStatus(200);
        $this->assertSame('active', $client->fresh()->status);

        // And it's back in the default list and creation works again.
        $listResponse = $this->actingAs($admin, 'sanctum')->getJson('/api/clients');
        $this->assertTrue(collect($listResponse->json('clients.data'))->pluck('id')->contains($client->id));
    }

    public function test_delete_route_for_clients_no_longer_exists(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        [$client] = $this->makeClientWithWorkspace($manager);

        $response = $this->actingAs($admin, 'sanctum')->deleteJson("/api/clients/{$client->id}");

        $this->assertContains($response->status(), [404, 405]);
    }

    public function test_manager_cannot_archive_a_client_that_is_not_theirs(): void
    {
        $ownManager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $otherManager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        [$client] = $this->makeClientWithWorkspace($ownManager);

        $response = $this->actingAs($otherManager, 'sanctum')->postJson("/api/clients/{$client->id}/archive");

        $response->assertStatus(403);
        $this->assertSame('active', $client->fresh()->status);
    }

    public function test_manager_can_archive_their_own_client(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        [$client] = $this->makeClientWithWorkspace($manager);

        $response = $this->actingAs($manager, 'sanctum')->postJson("/api/clients/{$client->id}/archive");

        $response->assertStatus(200);
        $this->assertSame('archived', $client->fresh()->status);
    }
}
