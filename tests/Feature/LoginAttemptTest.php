<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\LoginAttempt;
use App\Models\SubUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 20 Sept 2026 — failed sign-ins were never recorded anywhere, so "why
 * can't this person log in" had no evidence behind it and a sustained
 * guessing attack left no trace at all (the throttle stops it, but stops it
 * silently).
 *
 * These live in their own table rather than audit_logs because a failure
 * has no actor to hang a row on — see the create_login_attempts_table
 * migration.
 */
class LoginAttemptTest extends TestCase
{
    use RefreshDatabase;

    private function staffLogin(string $email, string $password)
    {
        return $this->postJson('/api/auth/login', ['email' => $email, 'password' => $password]);
    }

    private function clientLogin(string $email, string $password)
    {
        return $this->postJson('/api/auth/client/login', ['email' => $email, 'password' => $password]);
    }

    // ─── staff endpoint ─────────────────────────────────────────────

    public function test_an_unknown_email_on_the_staff_endpoint_is_recorded(): void
    {
        $this->staffLogin('nobody@test.com', 'whatever')->assertStatus(422);

        $this->assertDatabaseHas('login_attempts', [
            'email' => 'nobody@test.com',
            'endpoint' => LoginAttempt::ENDPOINT_STAFF,
            'reason' => LoginAttempt::REASON_UNKNOWN_EMAIL,
        ]);
    }

    public function test_a_wrong_password_is_recorded_separately_from_an_unknown_email(): void
    {
        User::factory()->create([
            'email' => 'staff@test.com',
            'password' => bcrypt('Password1'),
            'role' => User::ROLE_SUPER_ADMIN,
        ]);

        $this->staffLogin('staff@test.com', 'not-the-password')->assertStatus(422);

        // The API says the same thing either way on purpose; this
        // distinction exists only here, which is the whole point.
        $this->assertDatabaseHas('login_attempts', [
            'email' => 'staff@test.com',
            'endpoint' => LoginAttempt::ENDPOINT_STAFF,
            'reason' => LoginAttempt::REASON_WRONG_PASSWORD,
        ]);
    }

    public function test_a_deactivated_staff_account_is_recorded_with_its_own_reason(): void
    {
        User::factory()->create([
            'email' => 'staff@test.com',
            'password' => bcrypt('Password1'),
            'role' => User::ROLE_ACCOUNT_MANAGER,
            'is_active' => false,
        ]);

        $this->staffLogin('staff@test.com', 'Password1')->assertStatus(422);

        $this->assertDatabaseHas('login_attempts', [
            'email' => 'staff@test.com',
            'reason' => LoginAttempt::REASON_ACCOUNT_INACTIVE,
        ]);
    }

    public function test_a_successful_login_records_nothing(): void
    {
        User::factory()->create([
            'email' => 'staff@test.com',
            'password' => bcrypt('Password1'),
            'role' => User::ROLE_SUPER_ADMIN,
        ]);

        $this->staffLogin('staff@test.com', 'Password1')->assertOk();

        $this->assertDatabaseCount('login_attempts', 0);
    }

    // ─── the mobile fallback, which must NOT be recorded ────────────

    /**
     * The mobile app authenticates everyone by trying /auth/login first and
     * falling back to /auth/client/login (AuthProvider.authenticate), so a
     * client signing in normally always misses the staff endpoint on the
     * way. Recording that would roughly double this table with rows
     * describing successful usage and make a healthy system look besieged.
     */
    public function test_a_clients_email_missing_the_staff_endpoint_is_not_recorded(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $client = Client::factory()->create(['manager_id' => $manager->id, 'password' => 'Password1']);

        $this->staffLogin($client->email, 'Password1')->assertStatus(422);

        $this->assertDatabaseCount('login_attempts', 0);
    }

    public function test_a_sub_users_email_missing_the_staff_endpoint_is_not_recorded(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $client = Client::factory()->create(['manager_id' => $manager->id]);
        $subUser = SubUser::factory()->create(['client_id' => $client->id, 'password' => 'Password1']);

        $this->staffLogin($subUser->email, 'Password1')->assertStatus(422);

        $this->assertDatabaseCount('login_attempts', 0);
    }

    /**
     * The skip above is deliberately narrow: it only covers an unknown_email
     * miss. A real staff account failing on its password is still recorded
     * even if some client happens to share the address.
     */
    public function test_the_mobile_skip_does_not_hide_a_real_staff_password_failure(): void
    {
        User::factory()->create([
            'email' => 'shared@test.com',
            'password' => bcrypt('Password1'),
            'role' => User::ROLE_ACCOUNT_MANAGER,
        ]);
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        Client::factory()->create(['manager_id' => $manager->id, 'email' => 'shared@test.com']);

        $this->staffLogin('shared@test.com', 'wrong')->assertStatus(422);

        $this->assertDatabaseHas('login_attempts', [
            'email' => 'shared@test.com',
            'reason' => LoginAttempt::REASON_WRONG_PASSWORD,
        ]);
    }

    // ─── client endpoint ────────────────────────────────────────────

    public function test_a_wrong_client_password_is_recorded(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $client = Client::factory()->create(['manager_id' => $manager->id, 'password' => 'Password1']);

        $this->clientLogin($client->email, 'wrong')->assertStatus(422);

        $this->assertDatabaseHas('login_attempts', [
            'email' => $client->email,
            'endpoint' => LoginAttempt::ENDPOINT_CLIENT,
            'reason' => LoginAttempt::REASON_WRONG_PASSWORD,
        ]);
    }

    public function test_an_unknown_email_on_the_client_endpoint_is_recorded(): void
    {
        $this->clientLogin('nobody@test.com', 'whatever')->assertStatus(422);

        $this->assertDatabaseHas('login_attempts', [
            'email' => 'nobody@test.com',
            'endpoint' => LoginAttempt::ENDPOINT_CLIENT,
            'reason' => LoginAttempt::REASON_UNKNOWN_EMAIL,
        ]);
    }

    public function test_an_archived_client_with_the_right_password_is_recorded_as_archived(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $client = Client::factory()->create([
            'manager_id' => $manager->id,
            'password' => 'Password1',
            'status' => 'archived',
        ]);

        $this->clientLogin($client->email, 'Password1')->assertStatus(422);

        // Not a credential problem at all — support needs to see that
        // immediately rather than chasing a password.
        $this->assertDatabaseHas('login_attempts', [
            'email' => $client->email,
            'reason' => LoginAttempt::REASON_CLIENT_ARCHIVED,
        ]);
    }

    public function test_a_sub_user_of_an_archived_client_is_recorded_as_archived(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $client = Client::factory()->create(['manager_id' => $manager->id, 'status' => 'archived']);
        $subUser = SubUser::factory()->create(['client_id' => $client->id, 'password' => 'Password1']);

        $this->clientLogin($subUser->email, 'Password1')->assertStatus(422);

        $this->assertDatabaseHas('login_attempts', [
            'email' => $subUser->email,
            'reason' => LoginAttempt::REASON_CLIENT_ARCHIVED,
        ]);
    }

    // ─── what must never be stored ──────────────────────────────────

    public function test_the_attempted_password_is_never_stored_anywhere_on_the_row(): void
    {
        $this->staffLogin('nobody@test.com', 'hunter2-secret')->assertStatus(422);

        $attempt = LoginAttempt::first();
        $this->assertNotNull($attempt);
        $this->assertStringNotContainsString('hunter2-secret', json_encode($attempt->toArray()));
    }

    public function test_the_ip_address_is_recorded(): void
    {
        $this->staffLogin('nobody@test.com', 'whatever')->assertStatus(422);

        $this->assertNotNull(LoginAttempt::first()->ip_address);
    }

    // ─── pruning ────────────────────────────────────────────────────

    /**
     * created_at is deliberately not fillable — production only ever writes
     * "now", and leaving a timestamp mass-assignable invites it being set
     * from request data. So backdating a row for these tests needs
     * forceFill; passing created_at to create() would be silently dropped
     * and the row would land with today's date, which is exactly how the
     * first draft of these two tests failed.
     */
    private function attemptAgedDays(string $email, int $days): void
    {
        LoginAttempt::create([
            'email' => $email,
            'ip_address' => '127.0.0.1',
            'endpoint' => LoginAttempt::ENDPOINT_STAFF,
            'reason' => LoginAttempt::REASON_UNKNOWN_EMAIL,
        ])->forceFill(['created_at' => now()->subDays($days)])->save();
    }

    public function test_the_prune_command_deletes_only_rows_past_the_cutoff(): void
    {
        $this->attemptAgedDays('old@test.com', 120);
        $this->attemptAgedDays('recent@test.com', 10);

        $this->artisan('login-attempts:prune')->assertSuccessful();

        $this->assertDatabaseMissing('login_attempts', ['email' => 'old@test.com']);
        $this->assertDatabaseHas('login_attempts', ['email' => 'recent@test.com']);
    }

    public function test_the_prune_window_is_configurable(): void
    {
        $this->attemptAgedDays('old@test.com', 10);

        $this->artisan('login-attempts:prune', ['--days' => 5])->assertSuccessful();

        $this->assertDatabaseCount('login_attempts', 0);
    }
}
