<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The dashboard's client detail page now shows the client's uuid in the
 * browser URL instead of its numeric id (see the 2026_09_07 migration and
 * Client::resolveRouteBinding()). These tests pin down the two properties
 * that change depends on: every client automatically gets a uuid, and every
 * {client}-bound route keeps accepting the numeric id exactly as before
 * while also accepting the uuid.
 */
class ClientUuidRouteBindingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_client_is_assigned_a_uuid_automatically(): void
    {
        $client = Client::factory()->create();

        $this->assertNotNull($client->uuid);
        $this->assertTrue(Str::isUuid($client->uuid));
    }

    public function test_two_clients_never_collide_on_uuid(): void
    {
        $a = Client::factory()->create();
        $b = Client::factory()->create();

        $this->assertNotSame($a->uuid, $b->uuid);
    }

    public function test_show_route_still_resolves_by_numeric_id(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $client = Client::factory()->create(['manager_id' => $manager->id]);

        $response = $this->actingAs($manager, 'sanctum')->getJson("/api/clients/{$client->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('client.id', $client->id);
    }

    public function test_show_route_also_resolves_by_uuid(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $client = Client::factory()->create(['manager_id' => $manager->id]);

        $response = $this->actingAs($manager, 'sanctum')->getJson("/api/clients/{$client->uuid}");

        $response->assertStatus(200);
        $response->assertJsonPath('client.id', $client->id);
        $response->assertJsonPath('client.uuid', $client->uuid);
    }

    public function test_show_route_404s_for_an_unknown_uuid(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);

        $response = $this->actingAs($manager, 'sanctum')->getJson('/api/clients/' . Str::uuid());

        $response->assertStatus(404);
    }

    public function test_manager_still_cannot_view_another_managers_client_by_uuid(): void
    {
        // The uuid is just an alternate lookup key — it doesn't bypass the
        // existing per-tenant authorization checked on the resolved model.
        $ownerManager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $otherManager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $client = Client::factory()->create(['manager_id' => $ownerManager->id]);

        $response = $this->actingAs($otherManager, 'sanctum')->getJson("/api/clients/{$client->uuid}");

        $response->assertStatus(403);
    }
}
