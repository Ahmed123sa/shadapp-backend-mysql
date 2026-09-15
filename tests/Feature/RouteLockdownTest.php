<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Managers and clients can no longer be deleted through the API — see
 * AccountManagerController and ClientController for the full reasoning.
 * Both had cascade-delete relationships (managers cascaded through every
 * one of their clients' entire history; clients cascaded through their own
 * workspace, contracts, payments and signatures) with no undo path short
 * of restoring the whole database.
 *
 * This isn't a policy denial — the routes themselves are gone, so these
 * assert 404/405 rather than 403. If either route silently comes back
 * (e.g. someone re-adds `Route::delete` while fixing something unrelated),
 * this is what catches it.
 */
class RouteLockdownTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_manager_delete_route_no_longer_exists(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);

        $response = $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/account-managers/{$manager->id}");

        $this->assertContains($response->status(), [404, 405]);
        $this->assertNotNull($manager->fresh());
    }

    public function test_client_delete_route_no_longer_exists(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $client = Client::factory()->create(['manager_id' => $manager->id]);
        Workspace::factory()->create(['client_id' => $client->id, 'manager_id' => $manager->id]);

        $response = $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/clients/{$client->id}");

        $this->assertContains($response->status(), [404, 405]);
        $this->assertNotNull($client->fresh());
    }
}
