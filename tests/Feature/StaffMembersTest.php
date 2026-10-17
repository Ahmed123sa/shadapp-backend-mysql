<?php

namespace Tests\Feature;

use App\Mail\AccountCredentialsMail;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Company staff members, phase ف٢ (STAFF_TEAMS_PLAN.md): the super admin
 * creates / edits / archives them, they get their credentials by email, they
 * can sign in — and a deny-by-default wall keeps them off every route that
 * has not been explicitly opened to them. Also: manager assistants switched
 * off by config.
 */
class StaffMembersTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->superAdmin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $this->manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER, 'super_admin_id' => $this->superAdmin->id]);
    }

    private function member(array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'role' => User::ROLE_STAFF_MEMBER,
            'super_admin_id' => $this->superAdmin->id,
            'is_active' => true,
            'password' => 'Password123',
        ], $attrs));
    }

    private function assistant(array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'role' => User::ROLE_MANAGER_ASSISTANT,
            'parent_manager_id' => $this->manager->id,
            'assistant_permissions' => ['can_view_clients' => true],
            'is_active' => true,
            'password' => 'Password123',
        ], $attrs));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Huda',
            'email' => 'Huda@Example.com',
            'password' => 'Password123',
            'phone' => '0100000000',
        ], $overrides);
    }

    // ── create ──

    public function test_the_super_admin_creates_a_staff_member(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $this->postJson('/api/company-staff', $this->payload())
            ->assertCreated()
            ->assertJsonPath('staff.email', 'huda@example.com')
            ->assertJsonPath('staff.role', 'staff_member')
            ->assertJsonPath('staff.is_active', true)
            ->assertJsonPath('staff.archived_at', null);

        $this->assertDatabaseHas('users', ['email' => 'huda@example.com', 'role' => 'staff_member', 'super_admin_id' => $this->superAdmin->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'staff.created', 'user_id' => $this->superAdmin->id]);
    }

    public function test_the_new_staff_member_gets_their_credentials_by_email(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $this->postJson('/api/company-staff', $this->payload())->assertCreated();

        Mail::assertSent(AccountCredentialsMail::class, fn (AccountCredentialsMail $m) => $m->hasTo('huda@example.com') && $m->password === 'Password123');
    }

    public function test_the_credentials_email_can_be_switched_off(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $this->postJson('/api/company-staff', $this->payload(['send_email' => false]))->assertCreated();

        Mail::assertNothingSent();
    }

    public function test_a_weak_password_is_rejected(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $this->postJson('/api/company-staff', $this->payload(['password' => 'short']))->assertUnprocessable();
        $this->assertDatabaseMissing('users', ['email' => 'huda@example.com']);
    }

    public function test_the_email_must_be_unique_across_every_login_table(): void
    {
        Sanctum::actingAs($this->superAdmin);
        Client::factory()->create(['email' => 'taken@example.com', 'manager_id' => $this->manager->id]);

        $this->postJson('/api/company-staff', $this->payload(['email' => 'taken@example.com']))->assertUnprocessable();
        $this->postJson('/api/company-staff', $this->payload(['email' => $this->manager->email]))->assertUnprocessable();
    }

    // ── only the super admin ──

    public static function endpoints(): array
    {
        return [
            'list' => ['getJson', '/api/company-staff', []],
            'create' => ['postJson', '/api/company-staff', ['name' => 'X', 'email' => 'x@example.com', 'password' => 'Password123']],
            'update' => ['putJson', '/api/company-staff/{id}', ['name' => 'Y']],
            'password' => ['patchJson', '/api/company-staff/{id}/password', ['password' => 'Password123']],
            'archive' => ['postJson', '/api/company-staff/{id}/archive', []],
            'unarchive' => ['postJson', '/api/company-staff/{id}/unarchive', []],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_nobody_but_the_super_admin_can_manage_staff(string $method, string $path, array $body): void
    {
        $target = $this->member();
        $path = str_replace('{id}', (string) $target->id, $path);

        foreach ([$this->manager, $this->assistant(), $this->member(['email' => 'other@example.com'])] as $actor) {
            Sanctum::actingAs($actor);
            $this->{$method}($path, $body)->assertForbidden();
        }

        $this->assertDatabaseMissing('users', ['email' => 'x@example.com']);
    }

    public function test_the_routes_do_not_accept_a_non_staff_id(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $this->putJson("/api/company-staff/{$this->manager->id}", ['name' => 'Hacked'])->assertNotFound();
        $this->postJson("/api/company-staff/{$this->manager->id}/archive")->assertNotFound();
        $this->patchJson("/api/company-staff/{$this->manager->id}/password", ['password' => 'Password123'])->assertNotFound();

        $this->assertTrue($this->manager->fresh()->isActive());
    }

    // ── list ──

    public function test_the_list_shows_only_staff_members_and_hides_archived_by_default(): void
    {
        $a = $this->member(['name' => 'Aya', 'email' => 'aya@example.com']);
        $b = $this->member(['name' => 'Bassem', 'email' => 'bassem@example.com', 'is_active' => false, 'archived_at' => now()]);
        $this->assistant();

        Sanctum::actingAs($this->superAdmin);

        $ids = collect($this->getJson('/api/company-staff')->assertOk()->json('staff'))->pluck('id')->all();
        $this->assertSame([$a->id], $ids);

        $ids = collect($this->getJson('/api/company-staff?include_archived=1')->json('staff'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $ids);

        $ids = collect($this->getJson('/api/company-staff?include_archived=1&q=bass')->json('staff'))->pluck('id')->all();
        $this->assertSame([$b->id], $ids);
    }

    // ── edit ──

    public function test_the_super_admin_edits_name_and_email(): void
    {
        $m = $this->member();
        Sanctum::actingAs($this->superAdmin);

        $this->putJson("/api/company-staff/{$m->id}", ['name' => 'New Name', 'email' => 'New@Example.com', 'phone' => '0111'])
            ->assertOk()
            ->assertJsonPath('staff.name', 'New Name')
            ->assertJsonPath('staff.email', 'new@example.com');

        $this->assertDatabaseHas('audit_logs', ['action' => 'staff.updated', 'auditable_id' => $m->id]);
    }

    public function test_changing_the_email_ends_open_sessions_but_a_name_change_does_not(): void
    {
        $m = $this->member();
        $m->createToken('t');
        Sanctum::actingAs($this->superAdmin);

        $this->putJson("/api/company-staff/{$m->id}", ['name' => 'Renamed'])->assertOk();
        $this->assertSame(1, $m->tokens()->count());

        $this->putJson("/api/company-staff/{$m->id}", ['email' => 'moved@example.com'])->assertOk();
        $this->assertSame(0, $m->tokens()->count());
    }

    public function test_the_email_cannot_be_taken_from_someone_else(): void
    {
        $m = $this->member();
        Sanctum::actingAs($this->superAdmin);

        $this->putJson("/api/company-staff/{$m->id}", ['email' => $this->manager->email])->assertUnprocessable();
        // Saving their own unchanged email is fine.
        $this->putJson("/api/company-staff/{$m->id}", ['email' => $m->email])->assertOk();
    }

    public function test_the_password_change_revokes_sessions_and_emails_only_when_asked(): void
    {
        $m = $this->member();
        $m->createToken('t');
        Sanctum::actingAs($this->superAdmin);

        $this->patchJson("/api/company-staff/{$m->id}/password", ['password' => 'Another123'])->assertOk();
        $this->assertSame(0, $m->tokens()->count());
        Mail::assertNothingSent();

        $this->patchJson("/api/company-staff/{$m->id}/password", ['password' => 'Third12345', 'send_email' => true])->assertOk();
        Mail::assertSent(AccountCredentialsMail::class, fn (AccountCredentialsMail $mail) => $mail->password === 'Third12345');

        $this->postJson('/api/auth/login', ['email' => $m->email, 'password' => 'Third12345'])->assertOk();
    }

    // ── archive ──

    public function test_archiving_blocks_login_ends_sessions_and_keeps_the_name(): void
    {
        $m = $this->member(['name' => 'Karim']);
        $m->createToken('t');
        Sanctum::actingAs($this->superAdmin);

        $this->postJson("/api/company-staff/{$m->id}/archive")
            ->assertOk()
            ->assertJsonPath('staff.is_active', false);

        $fresh = $m->fresh();
        $this->assertNotNull($fresh->archived_at);
        $this->assertSame('Karim', $fresh->name);
        $this->assertSame(0, $fresh->tokens()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'staff.archived', 'auditable_id' => $m->id, 'user_id' => $this->superAdmin->id]);

        $this->postJson('/api/auth/login', ['email' => $m->email, 'password' => 'Password123'])->assertUnprocessable();
    }

    public function test_an_archived_member_with_a_live_token_is_refused(): void
    {
        $m = $this->member();
        $token = $m->createToken('t')->plainTextToken;
        $m->forceFill(['is_active' => false, 'archived_at' => now()])->save();

        $this->withToken($token)->getJson('/api/auth/me')->assertForbidden();
    }

    public function test_unarchiving_restores_access(): void
    {
        $m = $this->member(['is_active' => false, 'archived_at' => now()]);
        Sanctum::actingAs($this->superAdmin);

        $this->postJson("/api/company-staff/{$m->id}/unarchive")
            ->assertOk()
            ->assertJsonPath('staff.is_active', true)
            ->assertJsonPath('staff.archived_at', null);

        $this->assertDatabaseHas('audit_logs', ['action' => 'staff.unarchived', 'auditable_id' => $m->id]);
        $this->postJson('/api/auth/login', ['email' => $m->email, 'password' => 'Password123'])->assertOk();
    }

    public function test_there_is_no_delete_route(): void
    {
        $m = $this->member();
        Sanctum::actingAs($this->superAdmin);

        $this->deleteJson("/api/company-staff/{$m->id}")->assertStatus(405);
        $this->assertDatabaseHas('users', ['id' => $m->id]);
    }

    // ── a staff member signs in ──

    public function test_a_staff_member_logs_in_and_reads_their_own_profile(): void
    {
        $m = $this->member(['name' => 'Laila']);

        $token = $this->postJson('/api/auth/login', ['email' => $m->email, 'password' => 'Password123'])
            ->assertOk()
            ->assertJsonPath('user.role', 'staff_member')
            ->assertJsonPath('user.name', 'Laila')
            ->json('token');

        $this->withToken($token)->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.id', $m->id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'login', 'user_id' => $m->id]);
    }

    public function test_a_staff_member_can_edit_their_own_profile_and_log_out(): void
    {
        $m = $this->member();
        Sanctum::actingAs($m);

        $this->putJson('/api/auth/me', ['name' => 'Laila Z', 'date_of_birth' => '1995-03-02'])
            ->assertOk()
            ->assertJsonPath('user.name', 'Laila Z');

        $this->getJson('/api/notifications')->assertOk();
        $this->postJson('/api/notifications/read-all')->assertOk();
    }

    // ── deny by default ──

    public static function closedRoutes(): array
    {
        return [
            'clients list' => ['getJson', '/api/clients'],
            'create client' => ['postJson', '/api/clients'],
            'dashboard stats' => ['getJson', '/api/dashboard/stats'],
            'badge counts' => ['getJson', '/api/badge-counts'],
            'settings' => ['getJson', '/api/settings'],
            'users directory' => ['getJson', '/api/users'],
            'audit log' => ['getJson', '/api/audit-logs'],
            'reports' => ['getJson', '/api/reports'],
            'account managers' => ['getJson', '/api/account-managers'],
            'send fcm' => ['postJson', '/api/notifications/send-fcm'],
            'staff list' => ['getJson', '/api/company-staff'],
            'team' => ['getJson', '/api/team'],
            'payments' => ['getJson', '/api/all-payments'],
            'delete own account' => ['deleteJson', '/api/auth/account'],
            'sign' => ['postJson', '/api/auth/sign'],
        ];
    }

    #[DataProvider('closedRoutes')]
    public function test_a_staff_member_is_refused_everywhere_not_explicitly_opened(string $method, string $path): void
    {
        Sanctum::actingAs($this->member());

        $this->{$method}($path)->assertForbidden();
    }

    public function test_a_staff_member_cannot_open_a_real_workspace_yet(): void
    {
        $client = Client::factory()->create(['manager_id' => $this->manager->id]);
        $workspace = \App\Models\Workspace::factory()->create(['client_id' => $client->id, 'manager_id' => $this->manager->id]);

        Sanctum::actingAs($this->member());

        $this->getJson("/api/workspaces/{$workspace->id}/chat")->assertForbidden();
        $this->getJson("/api/workspaces/{$workspace->id}")->assertForbidden();
        $this->getJson("/api/clients/{$client->id}")->assertForbidden();
    }

    public function test_a_route_added_later_is_closed_to_staff_members_automatically(): void
    {
        Route::middleware(['auth:sanctum', 'restrict.roles'])->get('/api/_probe', fn () => response()->json(['ok' => true]));

        Sanctum::actingAs($this->member());
        $this->getJson('/api/_probe')->assertForbidden();

        Sanctum::actingAs($this->manager);
        $this->getJson('/api/_probe')->assertOk();

        Sanctum::actingAs($this->superAdmin);
        $this->getJson('/api/_probe')->assertOk();
    }

    public function test_every_authenticated_api_route_carries_the_role_gate(): void
    {
        $missing = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }
            $middleware = $route->gatherMiddleware();
            $authenticated = collect($middleware)->contains(fn ($m) => is_string($m) && (str_starts_with($m, 'auth:') || str_starts_with($m, 'auth.any')));
            if ($authenticated && ! in_array('restrict.roles', $middleware, true)) {
                $missing[] = implode('|', $route->methods()) . ' ' . $route->uri();
            }
        }

        $this->assertSame([], $missing, 'Authenticated routes without restrict.roles: ' . implode(', ', $missing));
    }

    public function test_managers_do_not_see_staff_members_in_the_users_directory(): void
    {
        $m = $this->member(['name' => 'Hidden Staff']);

        Sanctum::actingAs($this->manager);
        $this->assertNotContains($m->id, collect($this->getJson('/api/users')->json())->pluck('id')->all());

        Sanctum::actingAs($this->superAdmin);
        $this->assertContains($m->id, collect($this->getJson('/api/users')->json())->pluck('id')->all());
    }

    public function test_a_staff_member_owns_no_clients(): void
    {
        $m = $this->member();
        Client::factory()->create(['manager_id' => $this->manager->id]);

        $this->assertNotSame($this->manager->id, $m->ownerManagerId());
        $this->assertFalse($m->canApproveContracts());
        $this->assertFalse($m->isAccountManager());
        $this->assertFalse($m->isSuperAdmin());
    }

    // ── manager assistants switched off ──

    public function test_assistants_are_switched_off_by_config(): void
    {
        $assistant = $this->assistant();
        $token = $assistant->createToken('t')->plainTextToken;
        config(['team.assistants_enabled' => false]);

        // The assistant can neither sign in nor use a session opened before.
        $this->postJson('/api/auth/login', ['email' => $assistant->email, 'password' => 'Password123'])->assertUnprocessable();
        $this->withToken($token)->getJson('/api/auth/me')->assertForbidden();

        // The team endpoints are closed, even to the manager.
        Sanctum::actingAs($this->manager);
        $this->getJson('/api/team')->assertForbidden();
        $this->postJson('/api/team', ['name' => 'N', 'email' => 'n@example.com', 'password' => 'Password123'])->assertForbidden();

        // Nothing is deleted.
        $this->assertDatabaseHas('users', ['id' => $assistant->id, 'role' => 'manager_assistant', 'removed_at' => null]);
    }

    public function test_switching_the_flag_back_on_restores_assistants(): void
    {
        $assistant = $this->assistant();
        config(['team.assistants_enabled' => true]);

        $this->postJson('/api/auth/login', ['email' => $assistant->email, 'password' => 'Password123'])->assertOk();
        Sanctum::actingAs($this->manager);
        $this->getJson('/api/team')->assertOk();
    }

    public function test_the_manager_login_and_me_say_whether_assistants_are_on(): void
    {
        $this->manager->forceFill(['password' => 'Password123', 'is_active' => true])->save();

        config(['team.assistants_enabled' => false]);
        $this->postJson('/api/auth/login', ['email' => $this->manager->email, 'password' => 'Password123'])
            ->assertOk()
            ->assertJsonPath('user.team_assistants_enabled', false);

        config(['team.assistants_enabled' => true]);
        Sanctum::actingAs($this->manager);
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.team_assistants_enabled', true);
    }

    public function test_audit_rows_name_the_staff_member_after_archiving(): void
    {
        $m = $this->member(['name' => 'Salma']);
        Sanctum::actingAs($this->superAdmin);
        $this->postJson("/api/company-staff/{$m->id}/archive")->assertOk();

        $log = AuditLog::where('action', 'staff.archived')->firstOrFail();
        $this->assertSame('Salma', $log->metadata['name']);
        $this->assertSame('Salma', User::find($m->id)->name);
    }
}
