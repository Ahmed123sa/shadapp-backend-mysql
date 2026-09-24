<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 23 Sept 2026 — Sanctum tokens used to expire 24 hours after login (a
 * hardcoded 'expiration' => 1440 with no refresh), which logged mobile users
 * out every day. They now last 60 days by default (SANCTUM_EXPIRATION).
 */
class TokenExpirationTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('auth-token')->plainTextToken;
    }

    public function test_a_token_still_works_after_the_old_one_day_limit(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $token = $this->tokenFor($user);

        $this->travel(2)->days();

        $this->withToken($token)->getJson('/api/auth/me')->assertOk();
    }

    public function test_a_token_still_works_just_under_sixty_days(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $token = $this->tokenFor($user);

        $this->travel(59)->days();

        $this->withToken($token)->getJson('/api/auth/me')->assertOk();
    }

    public function test_a_token_expires_after_sixty_days(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $token = $this->tokenFor($user);

        $this->travel(61)->days();

        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }
}
