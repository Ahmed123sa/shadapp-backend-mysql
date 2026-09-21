<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\SubUser;
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

    // ─── Login auditing (20 Sept 2026) ──────────────────────────────
    //
    // Logins were never recorded at all before this: no audit_logs row, no
    // last_login_at column, nothing. AuditController::reports() has always
    // counted `action = 'login'` for its recent_logins stat and both
    // frontends surface it (the dashboard's "Login Activity" card, the
    // mobile audit log's "Logins" filter), so all three read a row type
    // nothing ever wrote and showed 0/empty permanently. These tests pin
    // down that the row is now written, and — just as importantly — that
    // each actor type lands in the right column, since audit_logs.user_id
    // is an FK into `users` and a client/sub-user id in it is the 1452
    // violation already fixed twice elsewhere in this project.

    public function test_a_successful_staff_login_is_recorded_in_the_audit_log(): void
    {
        $user = User::factory()->create([
            'email' => 'staff@test.com',
            'password' => bcrypt('Password1'),
            'role' => User::ROLE_SUPER_ADMIN,
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'staff@test.com',
            'password' => 'Password1',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'action' => 'login',
            'user_id' => $user->id,
            'client_id' => null,
            'metadata' => json_encode(['login_type' => 'staff']),
        ]);
    }

    public function test_a_successful_client_login_is_recorded_against_the_client_not_a_user_id(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $client = Client::factory()->create([
            'manager_id' => $manager->id,
            'password' => 'Password1',
        ]);

        $this->postJson('/api/auth/client/login', [
            'email' => $client->email,
            'password' => 'Password1',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Client::class,
            'auditable_id' => $client->id,
            'action' => 'login',
            'client_id' => $client->id,
            // Must stay null: writing the client's id here would be an FK
            // violation against `users` on MySQL/Postgres.
            'user_id' => null,
            'metadata' => json_encode(['login_type' => 'client']),
        ]);
    }

    public function test_a_successful_sub_user_login_is_recorded_against_the_parent_client(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $client = Client::factory()->create(['manager_id' => $manager->id]);
        $subUser = SubUser::factory()->create([
            'client_id' => $client->id,
            'password' => 'Password1',
        ]);

        $this->postJson('/api/auth/client/login', [
            'email' => $subUser->email,
            'password' => 'Password1',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => SubUser::class,
            'auditable_id' => $subUser->id,
            'action' => 'login',
            // The owning client, not the sub-user's own id — client_id is an
            // FK into `clients`.
            'client_id' => $client->id,
            'user_id' => null,
            'metadata' => json_encode(['login_type' => 'sub_user']),
        ]);
    }

    public function test_a_failed_login_is_not_recorded(): void
    {
        User::factory()->create([
            'email' => 'staff@test.com',
            'password' => bcrypt('Password1'),
            'role' => User::ROLE_SUPER_ADMIN,
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'staff@test.com',
            'password' => 'wrong-password',
        ])->assertStatus(422);

        // Deliberate, not an oversight: a failed attempt has no actor to
        // hang a row on (a wrong email matches nothing at all), and
        // audit_logs.auditable is a non-nullable morph. Failed attempts get
        // their own table in a later step — this test exists so that
        // decision is visible rather than looking like a gap.
        $this->assertDatabaseMissing('audit_logs', ['action' => 'login']);
    }

    public function test_the_reports_recent_logins_stat_counts_a_real_login(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin@test.com',
            'password' => bcrypt('Password1'),
            'role' => User::ROLE_SUPER_ADMIN,
        ]);

        // The whole point of the fix: this stat read 0 forever because
        // nothing wrote the rows it counts.
        $this->actingAs($admin)->getJson('/api/reports')
            ->assertOk()
            ->assertJsonPath('recent_logins', 0);

        $this->postJson('/api/auth/login', [
            'email' => 'admin@test.com',
            'password' => 'Password1',
        ])->assertOk();

        $this->actingAs($admin)->getJson('/api/reports')
            ->assertOk()
            ->assertJsonPath('recent_logins', 1);
    }
}
