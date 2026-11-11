<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class LoginRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_one_users_failed_logins_through_dashboard_proxy_do_not_lock_out_other_users(): void
    {
        User::factory()->create([
            'email' => 'victim@test.com',
            'password' => bcrypt('Password1'),
            'role' => User::ROLE_ACCOUNT_MANAGER,
        ]);
        User::factory()->create([
            'email' => 'other@test.com',
            'password' => bcrypt('Password1'),
            'role' => User::ROLE_ACCOUNT_MANAGER,
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
                ->withHeaders(['X-Forwarded-For' => '203.0.113.10'])
                ->postJson('/api/auth/login', [
                    'email' => 'victim@test.com',
                    'password' => 'wrong-password',
                ])->assertStatus(422);
        }

        // 6th attempt for victim@test.com from the same client IP is throttled.
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.10'])
            ->postJson('/api/auth/login', [
                'email' => 'victim@test.com',
                'password' => 'Password1',
            ])->assertStatus(429);

        // Another user behind the same dashboard proxy (different IP or even same NAT IP) can still log in.
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.11'])
            ->postJson('/api/auth/login', [
                'email' => 'other@test.com',
                'password' => 'Password1',
            ])->assertOk();

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.10'])
            ->postJson('/api/auth/login', [
                'email' => 'other@test.com',
                'password' => 'Password1',
            ])->assertOk();
    }

    public function test_untrusted_external_caller_cannot_spoof_x_forwarded_for_to_bypass_rate_limit(): void
    {
        User::factory()->create([
            'email' => 'staff@test.com',
            'password' => bcrypt('Password1'),
            'role' => User::ROLE_SUPER_ADMIN,
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.99'])
                ->withHeaders(['X-Forwarded-For' => '10.0.0.' . ($i + 1)])
                ->postJson('/api/auth/login', [
                    'email' => 'staff@test.com',
                    'password' => 'wrong',
                ])->assertStatus(422);
        }

        // Different spoofed X-Forwarded-For, same untrusted REMOTE_ADDR -> still blocked.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.99'])
            ->withHeaders(['X-Forwarded-For' => '10.0.0.250'])
            ->postJson('/api/auth/login', [
                'email' => 'staff@test.com',
                'password' => 'wrong',
            ])->assertStatus(429);
    }

    public function test_same_server_addr_proxy_is_trusted_for_x_forwarded_for(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->withServerVariables([
                'REMOTE_ADDR' => '192.0.2.50',
                'SERVER_ADDR' => '192.0.2.50',
            ])
                ->withHeaders(['X-Forwarded-For' => '203.0.113.20'])
                ->postJson('/api/auth/login', [
                    'email' => 'a@test.com',
                    'password' => 'wrong',
                ])->assertStatus(422);
        }

        // Another client IP arriving via the same VPS proxy is NOT throttled by the first IP's failures.
        $this->withServerVariables([
            'REMOTE_ADDR' => '192.0.2.50',
            'SERVER_ADDR' => '192.0.2.50',
        ])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.21'])
            ->postJson('/api/auth/login', [
                'email' => 'a@test.com',
                'password' => 'wrong',
            ])->assertStatus(422);
    }

    public function test_per_email_cap_blocks_distributed_brute_force_across_ips(): void
    {
        User::factory()->create([
            'email' => 'target@test.com',
            'password' => bcrypt('Password1'),
            'role' => User::ROLE_SUPER_ADMIN,
        ]);

        for ($i = 1; $i <= 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "198.51.100.{$i}"])
                ->postJson('/api/auth/login', [
                    'email' => 'Target@Test.com',
                    'password' => 'wrong',
                ])->assertStatus(422);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.99'])
            ->postJson('/api/auth/login', [
                'email' => 'target@test.com',
                'password' => 'Password1',
            ])->assertStatus(429);
    }

    public function test_mobile_staff_probe_does_not_block_client_login_endpoint(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $client = Client::factory()->create([
            'manager_id' => $manager->id,
            'email' => 'client@test.com',
            'password' => 'Password1',
        ]);

        // Simulate mobile trying /auth/login 5 times on staff bucket, then /auth/client/login.
        for ($i = 0; $i < 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.5'])
                ->postJson('/api/auth/login', [
                    'email' => $client->email,
                    'password' => 'Password1',
                ])->assertStatus(422);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.5'])
            ->postJson('/api/auth/client/login', [
                'email' => $client->email,
                'password' => 'Password1',
            ])->assertOk();
    }

    public function test_maps_url_must_be_https_when_provided(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);

        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/clients', [
                'company_name' => 'Acme',
                'contact_person' => 'Jane Doe',
                'email' => 'jane@acme.test',
                'phone' => '01000000000',
                'maps_url' => 'javascript:alert(1)',
            ])->assertStatus(422);

        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/clients', [
                'company_name' => 'Acme',
                'contact_person' => 'Jane Doe',
                'email' => 'jane@acme.test',
                'phone' => '01000000000',
                'maps_url' => 'http://maps.google.com/?q=30,31',
            ])->assertStatus(422);

        $create = $this->actingAs($manager, 'sanctum')
            ->postJson('/api/clients', [
                'company_name' => 'Acme',
                'contact_person' => 'Jane Doe',
                'email' => 'jane@acme.test',
                'phone' => '01000000000',
                'maps_url' => 'https://www.google.com/maps?q=30.0444,31.2357',
            ])->assertCreated();

        $clientId = $create->json('client.id');

        $this->actingAs($manager, 'sanctum')
            ->putJson("/api/clients/{$clientId}", [
                'maps_url' => 'not-a-url',
            ])->assertStatus(422);

        $this->actingAs($manager, 'sanctum')
            ->putJson("/api/clients/{$clientId}", [
                'maps_url' => 'https://maps.app.goo.gl/example123',
            ])->assertOk();
    }
}
