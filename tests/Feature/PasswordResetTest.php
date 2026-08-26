<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = User::factory()->create([
            'role' => User::ROLE_ACCOUNT_MANAGER,
            'email' => 'staff@test.com',
        ]);

        $this->client = Client::factory()->create([
            'email' => 'client@test.com',
            'manager_id' => $this->staff->id,
        ]);
    }

    /**
     * The endpoints are rate limited (3/min for the forgot endpoints). Tests
     * that fire several requests in a row would otherwise start getting 429s
     * for reasons unrelated to what they're checking.
     */
    private function withoutRateLimiting(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
    }

    // ── Requesting a link ────────────────────────────────────────────────

    public function test_staff_can_request_reset_link(): void
    {
        Notification::fake();

        $this->postJson('/api/auth/forgot-password', ['email' => 'staff@test.com'])
            ->assertStatus(200);

        Notification::assertSentTo(
            $this->staff,
            ResetPasswordNotification::class,
            fn ($notification) => $notification->accountType === 'staff'
        );
    }

    public function test_client_can_request_reset_link(): void
    {
        Notification::fake();

        $this->postJson('/api/auth/client/forgot-password', ['email' => 'client@test.com'])
            ->assertStatus(200);

        Notification::assertSentTo(
            $this->client,
            ResetPasswordNotification::class,
            fn ($notification) => $notification->accountType === 'client'
        );
    }

    /**
     * An unknown address is reported explicitly rather than answered with a
     * generic "sent". That is a deliberate product decision — accounts are
     * created by managers, so the realistic failure case is a typo, and a
     * silent success leaves the user waiting for an email that never comes.
     * The rate limit on the route is what stops this from being usable to
     * enumerate accounts; see routes/api.php.
     */
    public function test_unknown_email_is_reported_and_sends_nothing(): void
    {
        Notification::fake();

        $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@test.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        Notification::assertNothingSent();
    }

    public function test_staff_endpoint_does_not_send_to_a_client_account(): void
    {
        Notification::fake();

        // A client's address is not a staff account. The staff broker must not
        // find it — and must not quietly send anything either.
        $this->postJson('/api/auth/forgot-password', ['email' => 'client@test.com'])
            ->assertStatus(422);

        Notification::assertNothingSent();
    }

    /**
     * The mobile app calls both forgot endpoints for every request, because it
     * can't know which kind of account an address belongs to. This pins down
     * the shape that relies on: the matching one succeeds, the other returns
     * 422, and only the right person is emailed.
     */
    public function test_client_endpoint_rejects_a_staff_address(): void
    {
        Notification::fake();

        $this->postJson('/api/auth/client/forgot-password', ['email' => 'staff@test.com'])
            ->assertStatus(422);

        Notification::assertNothingSent();

        $this->postJson('/api/auth/forgot-password', ['email' => 'staff@test.com'])
            ->assertStatus(200);

        Notification::assertSentTo($this->staff, ResetPasswordNotification::class);
    }

    // ── Completing a reset ───────────────────────────────────────────────

    public function test_staff_can_reset_password_with_valid_token(): void
    {
        $token = Password::broker('users')->createToken($this->staff);

        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => 'staff@test.com',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertStatus(200);

        $this->assertTrue(Hash::check('new-password-123', $this->staff->fresh()->password));
    }

    public function test_client_can_reset_password_with_valid_token(): void
    {
        $token = Password::broker('clients')->createToken($this->client);

        $this->postJson('/api/auth/client/reset-password', [
            'token' => $token,
            'email' => 'client@test.com',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertStatus(200);

        $this->assertTrue(Hash::check('new-password-123', $this->client->fresh()->password));
    }

    public function test_reset_fails_with_invalid_token(): void
    {
        $original = $this->staff->password;

        $this->postJson('/api/auth/reset-password', [
            'token' => 'not-a-real-token',
            'email' => 'staff@test.com',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertStatus(422);

        $this->assertSame($original, $this->staff->fresh()->password);
    }

    public function test_reset_fails_when_password_confirmation_does_not_match(): void
    {
        $token = Password::broker('users')->createToken($this->staff);

        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => 'staff@test.com',
            'password' => 'new-password-123',
            'password_confirmation' => 'something-else',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_reset_rejects_short_password(): void
    {
        $token = Password::broker('users')->createToken($this->staff);

        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => 'staff@test.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_token_cannot_be_reused(): void
    {
        $token = Password::broker('users')->createToken($this->staff);

        $payload = [
            'token' => $token,
            'email' => 'staff@test.com',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ];

        $this->postJson('/api/auth/reset-password', $payload)->assertStatus(200);

        // Second attempt with the same token must fail — the broker deletes
        // the token on success, so a leaked link can't be replayed later.
        $this->postJson('/api/auth/reset-password', $payload)->assertStatus(422);
    }

    // ── Isolation between the two brokers ────────────────────────────────

    /**
     * The whole reason clients have their own token table. A token minted for
     * a client must be worthless against the staff endpoint even when both
     * accounts share an email address.
     */
    public function test_client_token_is_not_accepted_by_the_staff_endpoint(): void
    {
        $sharedEmail = 'shared@test.com';
        $staff = User::factory()->create(['email' => $sharedEmail]);
        $client = Client::factory()->create([
            'email' => $sharedEmail,
            'manager_id' => $this->staff->id,
        ]);

        $clientToken = Password::broker('clients')->createToken($client);
        $staffPasswordBefore = $staff->fresh()->password;

        $this->postJson('/api/auth/reset-password', [
            'token' => $clientToken,
            'email' => $sharedEmail,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertStatus(422);

        $this->assertSame($staffPasswordBefore, $staff->fresh()->password);
    }

    public function test_staff_and_client_tokens_are_stored_separately(): void
    {
        $sharedEmail = 'shared@test.com';
        $staff = User::factory()->create(['email' => $sharedEmail]);
        $client = Client::factory()->create([
            'email' => $sharedEmail,
            'manager_id' => $this->staff->id,
        ]);

        Password::broker('users')->createToken($staff);
        Password::broker('clients')->createToken($client);

        // Both survive: if they shared a table, the second createToken would
        // have overwritten the first (the tables are keyed by email alone).
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $sharedEmail]);
        $this->assertDatabaseHas('client_password_reset_tokens', ['email' => $sharedEmail]);

        $this->assertSame(
            1,
            DB::table('password_reset_tokens')->where('email', $sharedEmail)->count()
        );
        $this->assertSame(
            1,
            DB::table('client_password_reset_tokens')->where('email', $sharedEmail)->count()
        );
    }

    // ── Session invalidation ─────────────────────────────────────────────

    /**
     * If someone resets because their account was compromised, leaving the
     * attacker's existing API tokens valid would make the reset pointless.
     */
    public function test_existing_api_tokens_are_revoked_after_reset(): void
    {
        $this->staff->createToken('existing-session');
        $this->assertSame(1, $this->staff->tokens()->count());

        $token = Password::broker('users')->createToken($this->staff);

        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => 'staff@test.com',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertStatus(200);

        $this->assertSame(0, $this->staff->fresh()->tokens()->count());
    }

    public function test_client_can_log_in_with_the_new_password(): void
    {
        $this->withoutRateLimiting();

        $token = Password::broker('clients')->createToken($this->client);

        $this->postJson('/api/auth/client/reset-password', [
            'token' => $token,
            'email' => 'client@test.com',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertStatus(200);

        $this->postJson('/api/auth/client/login', [
            'email' => 'client@test.com',
            'password' => 'new-password-123',
        ])->assertStatus(200);
    }
}
