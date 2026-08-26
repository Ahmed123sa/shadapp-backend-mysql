<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_super_admin_registration_succeeds(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Admin',
            'email' => 'admin@test.com',
            'password' => 'Password1',
        ]);
        $response->assertCreated();
        $response->assertJsonStructure(['token', 'user']);
    }

    public function test_second_super_admin_registration_fails(): void
    {
        User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Admin2',
            'email' => 'admin2@test.com',
            'password' => 'Password1',
        ]);
        $response->assertForbidden();
    }

    public function test_login_with_valid_credentials_returns_token(): void
    {
        User::factory()->create([
            'email' => 'user@test.com',
            'password' => bcrypt('Password1'),
            'role' => User::ROLE_SUPER_ADMIN,
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'user@test.com',
            'password' => 'Password1',
        ]);
        $response->assertOk();
        $response->assertJsonStructure(['token', 'user']);
    }

    public function test_login_with_invalid_credentials_returns_422(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'wrong@test.com',
            'password' => 'wrong',
        ]);
        $response->assertStatus(422);
    }

    public function test_authenticated_user_can_logout(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/auth/logout');
        $response->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
    }
}
