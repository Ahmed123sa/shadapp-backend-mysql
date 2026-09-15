<?php

namespace Tests\Feature;

use App\Events\PaymentCreated;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Contract;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers ClientController::transfer — reassigning a client to a different
 * account manager.
 *
 * The single most important assertion here is
 * test_workspace_manager_id_is_updated_too: clients.manager_id and
 * workspaces.manager_id are two separate columns, and notification/email
 * recipients are resolved from workspaces.manager_id specifically (see
 * SendPaymentEmailNotification, SendContractEmailNotification,
 * ChatController). A transfer that updates the client row but forgets the
 * workspace row would look correct in the client list while silently
 * leaving every notification routed to the old manager — see
 * test_notifications_after_transfer_go_to_new_manager, which exercises that
 * exact path end to end.
 */
class ClientTransferTest extends TestCase
{
    use RefreshDatabase;

    private function makeClientWithWorkspace(User $manager): array
    {
        $client = Client::factory()->create(['manager_id' => $manager->id]);
        $workspace = Workspace::factory()->create([
            'client_id' => $client->id,
            'manager_id' => $manager->id,
        ]);

        return [$client, $workspace];
    }

    public function test_transfer_updates_client_manager_id(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $oldManager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $newManager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        [$client] = $this->makeClientWithWorkspace($oldManager);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/clients/{$client->id}/transfer", ['new_manager_id' => $newManager->id]);

        $response->assertStatus(200);
        $this->assertSame($newManager->id, $client->fresh()->manager_id);
    }

    public function test_workspace_manager_id_is_updated_too(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $oldManager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $newManager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        [$client, $workspace] = $this->makeClientWithWorkspace($oldManager);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/clients/{$client->id}/transfer", ['new_manager_id' => $newManager->id]);

        $this->assertSame($newManager->id, $workspace->fresh()->manager_id);
    }

    public function test_transfer_writes_an_audit_log_entry(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $oldManager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $newManager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        [$client] = $this->makeClientWithWorkspace($oldManager);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/clients/{$client->id}/transfer", ['new_manager_id' => $newManager->id]);

        $log = AuditLog::where('action', 'client.transferred')
            ->where('auditable_id', $client->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame($oldManager->id, $log->metadata['from_manager_id']);
        $this->assertSame($newManager->id, $log->metadata['to_manager_id']);
    }

    public function test_contract_created_by_does_not_change_after_transfer(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $oldManager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $newManager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        [$client, $workspace] = $this->makeClientWithWorkspace($oldManager);

        $contract = Contract::factory()->withCreator($oldManager)->create([
            'workspace_id' => $workspace->id,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/clients/{$client->id}/transfer", ['new_manager_id' => $newManager->id]);

        $this->assertSame($oldManager->id, $contract->fresh()->created_by);
    }

    public function test_account_manager_cannot_transfer_a_client(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $newManager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        [$client] = $this->makeClientWithWorkspace($manager);

        $response = $this->actingAs($manager, 'sanctum')
            ->postJson("/api/clients/{$client->id}/transfer", ['new_manager_id' => $newManager->id]);

        $response->assertStatus(403);
        $this->assertSame($manager->id, $client->fresh()->manager_id);
    }

    public function test_transfer_to_a_non_manager_user_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $oldManager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $notAManager = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        [$client] = $this->makeClientWithWorkspace($oldManager);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/clients/{$client->id}/transfer", ['new_manager_id' => $notAManager->id]);

        $response->assertStatus(422);
        $this->assertSame($oldManager->id, $client->fresh()->manager_id);
    }

    public function test_transfer_to_the_same_manager_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        [$client] = $this->makeClientWithWorkspace($manager);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/clients/{$client->id}/transfer", ['new_manager_id' => $manager->id]);

        $response->assertStatus(422);
    }

    public function test_new_manager_sees_the_client_after_transfer(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $oldManager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $newManager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        [$client] = $this->makeClientWithWorkspace($oldManager);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/clients/{$client->id}/transfer", ['new_manager_id' => $newManager->id]);

        $response = $this->actingAs($newManager, 'sanctum')->getJson('/api/clients');

        $response->assertStatus(200);
        $ids = collect($response->json('clients.data'))->pluck('id');
        $this->assertTrue($ids->contains($client->id));
    }

    public function test_old_manager_no_longer_sees_the_client_after_transfer(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $oldManager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $newManager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        [$client] = $this->makeClientWithWorkspace($oldManager);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/clients/{$client->id}/transfer", ['new_manager_id' => $newManager->id]);

        $response = $this->actingAs($oldManager, 'sanctum')->getJson('/api/clients');

        $response->assertStatus(200);
        $ids = collect($response->json('clients.data'))->pluck('id');
        $this->assertFalse($ids->contains($client->id));
    }

    /**
     * The regression test for the workspace/client column split: if a future
     * change updates clients.manager_id but not workspaces.manager_id (or
     * vice versa), this is what catches it — new-activity notifications are
     * resolved from the workspace relation, not the client one.
     */
    public function test_notifications_after_transfer_go_to_new_manager(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $oldManager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $newManager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        [$client, $workspace] = $this->makeClientWithWorkspace($oldManager);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/clients/{$client->id}/transfer", ['new_manager_id' => $newManager->id]);

        $payment = \App\Models\Payment::factory()->create([
            'workspace_id' => $workspace->fresh()->id,
            'client_id' => $client->id,
        ]);

        PaymentCreated::dispatch($payment);

        $this->assertGreaterThan(0, $newManager->fresh()->notifications()->count());
        $this->assertSame(0, $oldManager->fresh()->notifications()->count());
    }
}
