<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Per-account limit on the authenticated API (config app.api_rate_limit,
 * default 300/min). The limit is lowered to 3 here so it can be reached.
 */
class ApiRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.api_rate_limit' => 3]);
    }

    public function test_an_account_over_the_limit_gets_429(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        Sanctum::actingAs($manager);

        for ($i = 0; $i < 3; $i++) {
            $this->getJson('/api/notifications')->assertOk();
        }

        $this->getJson('/api/notifications')->assertStatus(429);
    }

    public function test_one_accounts_traffic_does_not_limit_another(): void
    {
        $a = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $b = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);

        Sanctum::actingAs($a);
        for ($i = 0; $i < 4; $i++) {
            $this->getJson('/api/notifications');
        }

        Sanctum::actingAs($b);
        $this->getJson('/api/notifications')->assertOk();
    }

    public function test_a_client_and_a_staff_user_with_the_same_id_are_counted_separately(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $client = Client::factory()->create(['manager_id' => $manager->id]);

        Sanctum::actingAs($manager);
        for ($i = 0; $i < 4; $i++) {
            $this->getJson('/api/notifications');
        }

        $this->app['auth']->forgetGuards();
        $this->actingAs($client, 'client')->getJson('/api/notifications')->assertOk();
    }
}
