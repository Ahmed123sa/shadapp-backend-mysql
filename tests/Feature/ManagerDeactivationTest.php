<?php

namespace Tests\Feature;

use App\Events\ContractSent;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Contract;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers AccountManagerController::deactivate/activate — the replacement for
 * the removed DELETE /account-managers/{manager} route (see
 * RouteLockdownTest). Deactivating never deletes anything: it blocks login,
 * revokes every existing session, and hides the manager from the default
 * assignment list, while every client/contract/payment they're tied to stays
 * completely untouched. See DATA_SAFETY_PLAN.md §2.2.
 */
class ManagerDeactivationTest extends TestCase
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

    public function test_deactivating_a_manager_with_clients_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $this->makeClientWithWorkspace($manager);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/account-managers/{$manager->id}/deactivate");

        $response->assertStatus(422);
        $this->assertTrue($manager->fresh()->isActive());
    }

    public function test_deactivating_a_manager_with_no_clients_succeeds(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/account-managers/{$manager->id}/deactivate");

        $response->assertStatus(200);
        $fresh = $manager->fresh();
        $this->assertFalse($fresh->isActive());
        $this->assertNotNull($fresh->deactivated_at);

        $log = AuditLog::where('action', 'account_manager.deactivated')
            ->where('auditable_id', $manager->id)
            ->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->user_id);
    }

    public function test_deactivation_revokes_every_token(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        // Simulates the manager being logged in on more than one device
        // (e.g. dashboard + mobile) at the moment they're deactivated.
        $manager->createToken('mobile-token');
        $manager->createToken('dashboard-token');
        $this->assertSame(2, $manager->tokens()->count());

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/account-managers/{$manager->id}/deactivate");

        $this->assertSame(0, $manager->tokens()->count());
    }

    public function test_deactivated_manager_cannot_log_in(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $manager = User::factory()->create([
            'role' => User::ROLE_ACCOUNT_MANAGER,
            'password' => bcrypt('correct-password-123'),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/account-managers/{$manager->id}/deactivate");

        $response = $this->postJson('/api/auth/login', [
            'email' => $manager->email,
            'password' => 'correct-password-123',
        ]);

        $response->assertStatus(422);
    }

    public function test_deactivated_manager_is_hidden_from_the_assignment_list(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER, 'super_admin_id' => $admin->id]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/account-managers/{$manager->id}/deactivate");

        $defaultList = $this->actingAs($admin, 'sanctum')->getJson('/api/account-managers');
        $ids = collect($defaultList->json('managers'))->pluck('id');
        $this->assertFalse($ids->contains($manager->id));

        $withInactive = $this->actingAs($admin, 'sanctum')->getJson('/api/account-managers?include_inactive=1');
        $ids = collect($withInactive->json('managers'))->pluck('id');
        $this->assertTrue($ids->contains($manager->id));
    }

    public function test_deactivated_manager_does_not_receive_notifications(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $oldManager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $newManager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        [$client, $workspace] = $this->makeClientWithWorkspace($oldManager);

        // created_by is fixed history — it stays pointing at oldManager even
        // after the client moves to newManager via transfer, which is
        // exactly the scenario where a deactivated manager could otherwise
        // still receive contract emails/notifications for old work.
        $contract = Contract::factory()->withCreator($oldManager)->create([
            'workspace_id' => $workspace->id,
            'status' => 'draft',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/clients/{$client->id}/transfer", ['new_manager_id' => $newManager->id]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/account-managers/{$oldManager->id}/deactivate")
            ->assertStatus(200);

        ContractSent::dispatch($contract->fresh());

        $this->assertSame(0, $oldManager->fresh()->notifications()->count());
    }

    public function test_reactivation_restores_login(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $manager = User::factory()->create([
            'role' => User::ROLE_ACCOUNT_MANAGER,
            'password' => bcrypt('correct-password-123'),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/account-managers/{$manager->id}/deactivate");

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/account-managers/{$manager->id}/activate");

        $response->assertStatus(200);
        $fresh = $manager->fresh();
        $this->assertTrue($fresh->isActive());
        $this->assertNull($fresh->deactivated_at);

        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => $manager->email,
            'password' => 'correct-password-123',
        ]);
        $loginResponse->assertStatus(200);

        $log = AuditLog::where('action', 'account_manager.activated')
            ->where('auditable_id', $manager->id)
            ->first();
        $this->assertNotNull($log);
    }

    public function test_account_manager_cannot_deactivate_anyone(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $target = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);

        $response = $this->actingAs($manager, 'sanctum')
            ->postJson("/api/account-managers/{$target->id}/deactivate");

        $response->assertStatus(403);
        $this->assertTrue($target->fresh()->isActive());
    }
}
