<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\SubUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 20 Sept 2026 — /users, /audit-logs and /reports sit in the route group
 * labelled "Authenticated routes (Dashboard - SuperAdmin / AccountManager)"
 * and guarded by `auth:sanctum`, which reads like a staff-only group but
 * isn't one: Sanctum's guard resolves any valid personal access token to
 * whatever model issued it, so a Client or SubUser token authenticates
 * there too (DATA_SAFETY_PLAN.md §7.4).
 *
 * What that meant before the staff.only middleware:
 *   - /audit-logs and /reports call $user->isAccountManager(), a method
 *     only User has, so a client token produced a fatal error (500) rather
 *     than a refusal — and the "not an account manager" branch treats the
 *     caller as a super admin, i.e. returns the whole unscoped audit log.
 *   - /users never touches $request->user() at all, so it didn't even fail
 *     loudly: it simply returned the full staff directory (id, name,
 *     email) to any authenticated client or sub-user.
 *
 * Every non-staff case below authenticates with a REAL bearer token rather
 * than actingAs($client, 'client'). That is load-bearing: actingAs only
 * populates the named guard, which `auth:sanctum` never consults, so those
 * requests would 401 at the middleware and never reach the code under test
 * — the same false-negative that made MeetingTest's first draft pass for
 * the wrong reason.
 */
class StaffOnlyRouteTest extends TestCase
{
    use RefreshDatabase;

    /** Routes that must be reachable by staff and by nobody else. */
    public static function staffOnlyRoutes(): array
    {
        return [
            'users list' => ['/api/users'],
            'audit log' => ['/api/audit-logs'],
            'reports' => ['/api/reports'],
            // 24 Sept 2026 — same isAccountManager()-based scoping as
            // /reports (DashboardController::stats(), server-side-stats-plan.md).
            'dashboard stats' => ['/api/dashboard/stats'],
            // 26 Sept 2026 — same isAccountManager()-based scoping
            // (DashboardController::pendingApprovals(), pending-approvals-plan.md ك1).
            'pending approvals' => ['/api/dashboard/pending-approvals'],
        ];
    }

    private function makeClient(): Client
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);

        return Client::factory()->create(['manager_id' => $manager->id]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('staffOnlyRoutes')]
    public function test_a_client_token_is_refused(string $route): void
    {
        $token = $this->makeClient()->createToken('test')->plainTextToken;

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson($route)
            ->assertStatus(403);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('staffOnlyRoutes')]
    public function test_a_sub_user_token_is_refused(string $route): void
    {
        $subUser = SubUser::factory()->create(['client_id' => $this->makeClient()->id]);
        $token = $subUser->createToken('test')->plainTextToken;

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson($route)
            ->assertStatus(403);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('staffOnlyRoutes')]
    public function test_a_super_admin_still_has_access(string $route): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $this->actingAs($admin)->getJson($route)->assertOk();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('staffOnlyRoutes')]
    public function test_an_account_manager_still_has_access(string $route): void
    {
        // The regression that matters most here: account managers are
        // legitimate users of all three (the dashboard's audit-log filters
        // and the mobile AM reports page both call them), so the fix must
        // not lock them out while keeping clients out.
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);

        $this->actingAs($manager)->getJson($route)->assertOk();
    }

    public function test_an_unauthenticated_request_is_still_rejected(): void
    {
        $this->getJson('/api/audit-logs')->assertStatus(401);
    }
}
