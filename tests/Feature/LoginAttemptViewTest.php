<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\LoginAttempt;
use App\Models\SubUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 20 Sept 2026 — the read side of login_attempts (GET /login-attempts).
 *
 * Two things are being pinned down here. First that the route is genuinely
 * staff-only, which its route group does not provide (Sanctum's guard
 * admits any token type — see StaffOnly). Second that an account manager
 * sees only attempts against their own clients, since the alternative
 * would let any AM watch sign-in attempts against colleagues' clients and
 * against the super admin's own account.
 */
class LoginAttemptViewTest extends TestCase
{
    use RefreshDatabase;

    private function attempt(string $email, string $reason = LoginAttempt::REASON_WRONG_PASSWORD): LoginAttempt
    {
        return LoginAttempt::create([
            'email' => $email,
            'ip_address' => '127.0.0.1',
            'endpoint' => LoginAttempt::ENDPOINT_CLIENT,
            'reason' => $reason,
        ]);
    }

    public function test_a_super_admin_sees_every_attempt(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $this->attempt('someone@test.com');
        $this->attempt('nobody@test.com', LoginAttempt::REASON_UNKNOWN_EMAIL);

        $this->actingAs($admin)->getJson('/api/login-attempts')
            ->assertOk()
            ->assertJsonCount(2, 'attempts.data');
    }

    public function test_an_account_manager_sees_attempts_against_their_own_clients(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $client = Client::factory()->create(['manager_id' => $manager->id]);
        $this->attempt($client->email);

        $this->actingAs($manager)->getJson('/api/login-attempts')
            ->assertOk()
            ->assertJsonCount(1, 'attempts.data')
            ->assertJsonPath('attempts.data.0.email', $client->email);
    }

    public function test_an_account_manager_sees_attempts_against_their_clients_sub_users(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $client = Client::factory()->create(['manager_id' => $manager->id]);
        $subUser = SubUser::factory()->create(['client_id' => $client->id]);
        $this->attempt($subUser->email);

        $this->actingAs($manager)->getJson('/api/login-attempts')
            ->assertOk()
            ->assertJsonCount(1, 'attempts.data');
    }

    public function test_an_account_manager_cannot_see_another_managers_clients(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $otherManager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $otherClient = Client::factory()->create(['manager_id' => $otherManager->id]);
        $this->attempt($otherClient->email);

        $this->actingAs($manager)->getJson('/api/login-attempts')
            ->assertOk()
            ->assertJsonCount(0, 'attempts.data');
    }

    /**
     * Documented consequence of the scoping rather than an oversight: an
     * email matching no account matches no client of theirs either, so
     * bot/typo traffic is visible to the super admin only.
     */
    public function test_an_account_manager_does_not_see_unknown_email_noise(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        Client::factory()->create(['manager_id' => $manager->id]);
        $this->attempt('random-bot@test.com', LoginAttempt::REASON_UNKNOWN_EMAIL);

        $this->actingAs($manager)->getJson('/api/login-attempts')
            ->assertOk()
            ->assertJsonCount(0, 'attempts.data');
    }

    public function test_it_filters_by_reason(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $this->attempt('a@test.com', LoginAttempt::REASON_WRONG_PASSWORD);
        $this->attempt('b@test.com', LoginAttempt::REASON_CLIENT_ARCHIVED);

        $this->actingAs($admin)->getJson('/api/login-attempts?reason=' . LoginAttempt::REASON_CLIENT_ARCHIVED)
            ->assertOk()
            ->assertJsonCount(1, 'attempts.data')
            ->assertJsonPath('attempts.data.0.email', 'b@test.com');
    }

    public function test_it_filters_by_email_search(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $this->attempt('wanted@test.com');
        $this->attempt('other@test.com');

        $this->actingAs($admin)->getJson('/api/login-attempts?search=wanted')
            ->assertOk()
            ->assertJsonCount(1, 'attempts.data');
    }

    // ─── access control ─────────────────────────────────────────────

    public function test_a_client_token_is_refused(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $client = Client::factory()->create(['manager_id' => $manager->id]);
        // A real bearer token, not actingAs: the route sits behind plain
        // auth:sanctum, which actingAs($client, 'client') never reaches.
        $token = $client->createToken('test')->plainTextToken;

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/login-attempts')
            ->assertStatus(403);
    }

    public function test_a_sub_user_token_is_refused(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $client = Client::factory()->create(['manager_id' => $manager->id]);
        $subUser = SubUser::factory()->create(['client_id' => $client->id]);
        $token = $subUser->createToken('test')->plainTextToken;

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/login-attempts')
            ->assertStatus(403);
    }

    public function test_an_unauthenticated_request_is_refused(): void
    {
        $this->getJson('/api/login-attempts')->assertStatus(401);
    }
}
