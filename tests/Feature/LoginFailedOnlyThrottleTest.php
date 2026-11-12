<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\SubUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The per-account sign-in limit counts FAILED attempts only. Signing in
 * correctly any number of times never locks an account, and a successful
 * sign-in wipes the failures counted before it.
 */
class LoginFailedOnlyThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function staffLogin(string $email, string $password, string $ip = '198.51.100.7')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/auth/login', ['email' => $email, 'password' => $password]);
    }

    private function clientLogin(string $email, string $password, string $ip = '198.51.100.7')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/auth/client/login', ['email' => $email, 'password' => $password]);
    }

    private function manager(): User
    {
        return User::factory()->create([
            'email' => 'manager@test.com',
            'password' => bcrypt('Password1'),
            'role' => User::ROLE_ACCOUNT_MANAGER,
        ]);
    }

    public function test_signing_in_correctly_many_times_never_locks_a_staff_account(): void
    {
        $this->manager();

        for ($i = 0; $i < 15; $i++) {
            $this->staffLogin('manager@test.com', 'Password1')->assertOk();
        }
    }

    public function test_a_successful_sign_in_resets_the_failures_from_one_ip(): void
    {
        $this->manager();

        for ($i = 0; $i < 4; $i++) {
            $this->staffLogin('manager@test.com', 'wrong')->assertStatus(422);
        }
        $this->staffLogin('manager@test.com', 'Password1')->assertOk();

        // Without the reset this 5th+ failure would already be 429.
        for ($i = 0; $i < 4; $i++) {
            $this->staffLogin('manager@test.com', 'wrong')->assertStatus(422);
        }
        $this->staffLogin('manager@test.com', 'Password1')->assertOk();
    }

    public function test_a_successful_sign_in_resets_the_per_email_failures_from_any_ip(): void
    {
        $this->manager();

        for ($i = 1; $i <= 9; $i++) {
            $this->staffLogin('manager@test.com', 'wrong', "203.0.113.{$i}")->assertStatus(422);
        }
        $this->staffLogin('manager@test.com', 'Password1', '203.0.113.50')->assertOk();

        for ($i = 11; $i <= 19; $i++) {
            $this->staffLogin('manager@test.com', 'wrong', "203.0.113.{$i}")->assertStatus(422);
        }
        $this->staffLogin('manager@test.com', 'Password1', '203.0.113.51')->assertOk();
    }

    public function test_five_failures_still_lock_even_the_right_password(): void
    {
        $this->manager();

        for ($i = 0; $i < 5; $i++) {
            $this->staffLogin('manager@test.com', 'wrong')->assertStatus(422);
        }

        $this->staffLogin('manager@test.com', 'Password1')
            ->assertStatus(429)
            ->assertJsonPath('code', 'too_many_login_attempts')
            ->assertHeader('Retry-After');
    }

    public function test_the_lock_on_one_account_does_not_touch_another_account(): void
    {
        $this->manager();
        User::factory()->create(['email' => 'other@test.com', 'password' => bcrypt('Password1'), 'role' => User::ROLE_ACCOUNT_MANAGER]);

        for ($i = 0; $i < 5; $i++) {
            $this->staffLogin('manager@test.com', 'wrong')->assertStatus(422);
        }

        $this->staffLogin('other@test.com', 'Password1')->assertOk();
    }

    public function test_signing_in_correctly_many_times_never_locks_a_client_or_a_sub_user(): void
    {
        $manager = $this->manager();
        $client = Client::factory()->create(['manager_id' => $manager->id, 'email' => 'client@test.com', 'password' => 'Password1']);
        SubUser::factory()->create(['client_id' => $client->id, 'email' => 'sub@test.com', 'password' => 'Password1']);

        for ($i = 0; $i < 12; $i++) {
            $this->clientLogin('client@test.com', 'Password1')->assertOk();
            $this->clientLogin('sub@test.com', 'Password1')->assertOk();
        }
    }

    public function test_client_failures_lock_and_a_success_resets_them(): void
    {
        $manager = $this->manager();
        Client::factory()->create(['manager_id' => $manager->id, 'email' => 'client@test.com', 'password' => 'Password1']);

        for ($i = 0; $i < 4; $i++) {
            $this->clientLogin('client@test.com', 'wrong')->assertStatus(422);
        }
        $this->clientLogin('client@test.com', 'Password1')->assertOk();

        for ($i = 0; $i < 5; $i++) {
            $this->clientLogin('client@test.com', 'wrong')->assertStatus(422);
        }
        $this->clientLogin('client@test.com', 'Password1')->assertStatus(429);
    }

    public function test_the_mobile_staff_probe_never_locks_a_client_who_signs_in_often(): void
    {
        $manager = $this->manager();
        Client::factory()->create(['manager_id' => $manager->id, 'email' => 'client@test.com', 'password' => 'Password1']);

        // The app tries the staff endpoint first, then the client endpoint.
        for ($i = 0; $i < 12; $i++) {
            $this->staffLogin('client@test.com', 'Password1')->assertStatus(422);
            $this->clientLogin('client@test.com', 'Password1')->assertOk();
        }
    }

    public function test_one_ip_is_still_capped_across_many_emails(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->staffLogin("nobody{$i}@test.com", 'wrong', '198.51.100.200')->assertStatus(422);
        }

        $this->staffLogin('nobody31@test.com', 'wrong', '198.51.100.200')->assertStatus(429);
    }
}
