<?php

namespace Tests\Feature;

use App\Http\Middleware\RestrictRoles;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Payment;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\PaymentCreatedNotification;
use App\Notifications\PaymentReviewedStaffNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * ACCOUNTANT_ROLE_PLAN.md. The accountant sees the money side of the whole
 * company and may approve or reject a payment — nothing else. The wall is the
 * API itself (RestrictRoles), so most of these tests hit the API, not a screen.
 */
class AccountantRoleTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private User $accountant;
    private User $managerA;
    private User $managerB;
    private Client $clientA;
    private Client $clientB;
    private Workspace $wsA;
    private Workspace $wsB;
    private Payment $payA;
    private Payment $payB;
    private Contract $contractA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $this->accountant = User::factory()->create(['role' => User::ROLE_ACCOUNTANT, 'super_admin_id' => $this->superAdmin->id, 'is_active' => true]);
        $this->managerA = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER, 'super_admin_id' => $this->superAdmin->id, 'is_active' => true]);
        $this->managerB = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER, 'super_admin_id' => $this->superAdmin->id, 'is_active' => true]);

        $this->clientA = Client::factory()->create(['manager_id' => $this->managerA->id]);
        $this->clientB = Client::factory()->create(['manager_id' => $this->managerB->id]);
        $this->wsA = Workspace::factory()->create(['client_id' => $this->clientA->id, 'manager_id' => $this->managerA->id, 'status' => 'active']);
        $this->wsB = Workspace::factory()->create(['client_id' => $this->clientB->id, 'manager_id' => $this->managerB->id, 'status' => 'active']);

        $this->contractA = Contract::factory()->create(['workspace_id' => $this->wsA->id, 'status' => 'completed']);
        $this->payA = Payment::factory()->create(['workspace_id' => $this->wsA->id, 'client_id' => $this->clientA->id, 'status' => 'pending', 'currency' => 'SAR', 'amount' => 1000]);
        $this->payB = Payment::factory()->create(['workspace_id' => $this->wsB->id, 'client_id' => $this->clientB->id, 'status' => 'pending', 'currency' => 'USD', 'amount' => 2000]);
    }

    /* ------------------------------------------------------------------ reach */

    public function test_accountant_sees_every_managers_payments_contracts_and_clients(): void
    {
        Sanctum::actingAs($this->accountant);

        $this->getJson('/api/all-payments')->assertOk()->assertJsonCount(2, 'payments.data');
        $this->getJson('/api/payments/pending')->assertOk()->assertJsonCount(2, 'payments.data');
        $this->getJson('/api/all-contracts')->assertOk()->assertJsonCount(1, 'contracts.data');
        $this->getJson('/api/clients')->assertOk()->assertJsonCount(2, 'clients.data');
        $this->getJson("/api/clients/{$this->clientB->id}")->assertOk()->assertJsonPath('client.id', $this->clientB->id);
        $this->getJson("/api/workspaces/{$this->wsB->id}/payments")->assertOk()->assertJsonCount(1, 'payments');
        $this->getJson("/api/workspaces/{$this->wsA->id}/contracts")->assertOk();
        $this->getJson("/api/contracts/{$this->contractA->id}")->assertOk();
        $this->getJson('/api/account-managers')->assertOk()->assertJsonCount(2, 'managers');
        $this->getJson('/api/company-staff')->assertOk();
        $this->getJson('/api/dashboard/stats')->assertOk()->assertJsonPath('payments.pending', 2);
        $this->getJson('/api/badge-counts')->assertOk()->assertJsonPath('payments', 2);
    }

    public function test_accountant_reads_an_archived_clients_money_history(): void
    {
        $this->clientA->update(['status' => 'archived']);
        Sanctum::actingAs($this->accountant);

        $this->getJson('/api/all-payments')->assertOk()->assertJsonCount(2, 'payments.data');
        $this->getJson('/api/clients?include_archived=1')->assertOk()->assertJsonCount(2, 'clients.data');
    }

    public function test_responses_carry_the_finance_side_only(): void
    {
        Sanctum::actingAs($this->accountant);

        $client = $this->getJson("/api/clients/{$this->clientA->id}")->assertOk()->json('client');
        foreach (['sub_users', 'subUsers', 'notes', 'signature_data', 'password'] as $key) {
            $this->assertArrayNotHasKey($key, $client, "client.$key must not reach the accountant");
        }
        $this->assertArrayHasKey('contracts', $client['workspace']);
        $this->assertArrayHasKey('payments', $client['workspace']);

        $ws = $this->getJson("/api/workspaces/{$this->wsA->id}")->assertOk()->json('workspace');
        foreach (['chat_messages', 'chatMessages', 'files', 'meetings', 'approvals'] as $key) {
            $this->assertArrayNotHasKey($key, $ws, "workspace.$key must not reach the accountant");
        }

        $contract = $this->getJson("/api/contracts/{$this->contractA->id}")->assertOk()->json('contract');
        $this->assertArrayNotHasKey('required_documents', $contract);
    }

    /* --------------------------------------------------------------- the wall */

    public function test_every_route_outside_the_allow_list_is_refused_for_the_accountant(): void
    {
        $middleware = app(RestrictRoles::class);
        $checked = 0;
        $reached = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/') || ! in_array('restrict.roles', $route->gatherMiddleware(), true)) {
                continue;
            }

            $concrete = preg_replace('/\{[^}]+\}/', '1', $uri);

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $request = Request::create('/' . $concrete, $method);
                $request->setRouteResolver(fn () => $route);
                $request->setUserResolver(fn () => $this->accountant);

                try {
                    $middleware->handle($request, fn () => response('through'));
                    $reached[] = "$method $uri";
                } catch (HttpException $e) {
                    $this->assertSame(403, $e->getStatusCode(), "$method $uri");
                }
                $checked++;
            }
        }

        $this->assertGreaterThan(100, $checked, 'the walk must actually cover the API');

        $expected = array_merge(RestrictRoles::ACCOUNTANT_ALLOWED_ROUTES, [
            'POST api/auth/logout', 'GET api/auth/me', 'POST api/auth/me', 'PUT api/auth/me', 'POST api/broadcasting/auth', 'GET api/broadcasting/auth',
            'GET api/notifications', 'POST api/notifications/read-all', 'POST api/notifications/{id}/read', 'DELETE api/notifications/{id}',
            'POST api/notifications/register-token', 'POST api/notifications/unregister-token',
        ]);
        sort($reached);
        sort($expected);
        $this->assertSame([], array_values(array_diff($reached, $expected)), 'routes the accountant can reach beyond the allow-list');
    }

    public function test_the_accountant_cannot_write_anything_but_a_payment_review(): void
    {
        Sanctum::actingAs($this->accountant);
        $ws = $this->wsA->id;

        $this->postJson('/api/clients', ['company_name' => 'X'])->assertForbidden();
        $this->putJson("/api/clients/{$this->clientA->id}", ['company_name' => 'Y'])->assertForbidden();
        $this->postJson("/api/clients/{$this->clientA->id}/archive")->assertForbidden();
        $this->postJson("/api/clients/{$this->clientA->id}/transfer", ['new_manager_id' => $this->managerB->id])->assertForbidden();
        $this->postJson("/api/workspaces/{$ws}/contracts", ['title' => 'x'])->assertForbidden();
        $this->putJson("/api/contracts/{$this->contractA->id}", ['title' => 'x'])->assertForbidden();
        $this->deleteJson("/api/contracts/{$this->contractA->id}")->assertForbidden();
        $this->postJson("/api/contracts/{$this->contractA->id}/company-approve")->assertForbidden();
        $this->postJson("/api/contracts/{$this->contractA->id}/complete")->assertForbidden();
        $this->postJson("/api/workspaces/{$ws}/payments", ['amount' => 5])->assertForbidden();
        $this->postJson("/api/workspaces/{$ws}/payments/schedule", ['installments' => []])->assertForbidden();
        $this->postJson("/api/workspaces/{$ws}/payments/request", ['amount' => 5])->assertForbidden();
        $this->deleteJson("/api/payments/{$this->payA->id}/schedule")->assertForbidden();
        $this->postJson('/api/account-managers', ['name' => 'x'])->assertForbidden();
        $this->postJson('/api/company-staff', ['name' => 'x'])->assertForbidden();
        $this->postJson('/api/accountants', ['name' => 'x'])->assertForbidden();
        $this->putJson('/api/settings', ['key' => 'corporate_tax_percentage', 'value' => 1])->assertForbidden();

        $this->assertSame('pending', $this->payA->fresh()->status);
    }

    public function test_the_accountant_cannot_read_chat_meetings_files_approvals_or_reports(): void
    {
        Sanctum::actingAs($this->accountant);
        $ws = $this->wsA->id;

        foreach ([
            "/api/workspaces/{$ws}/chat", "/api/workspaces/{$ws}/meetings", "/api/workspaces/{$ws}/files",
            "/api/workspaces/{$ws}/approvals", "/api/contracts/{$this->contractA->id}/files",
            "/api/contracts/{$this->contractA->id}/required-documents", '/api/all-files', '/api/all-meetings',
            '/api/approvals/pending', '/api/reports', '/api/audit-logs', '/api/login-attempts', '/api/users',
            '/api/my-team', '/api/team', '/api/accountants',
        ] as $url) {
            $this->getJson($url)->assertForbidden();
        }
    }

    public function test_a_deactivated_accountant_is_refused_everywhere(): void
    {
        $this->accountant->update(['is_active' => false]);
        Sanctum::actingAs($this->accountant);

        $this->getJson('/api/all-payments')->assertForbidden();
        $this->getJson('/api/auth/me')->assertForbidden();
    }

    public function test_the_accountant_never_joins_a_workspace_channel(): void
    {
        config(['broadcasting.default' => 'reverb']);
        Broadcast::setDefaultDriver('reverb');
        require base_path('routes/channels.php');

        Sanctum::actingAs($this->accountant, ['*']);

        $this->postJson('/api/broadcasting/auth', ['socket_id' => '1.1', 'channel_name' => "private-workspace.{$this->wsA->id}"])->assertForbidden();
        $this->postJson('/api/broadcasting/auth', ['socket_id' => '1.1', 'channel_name' => "private-App.Models.User.{$this->accountant->id}"])->assertOk();
    }

    /* ----------------------------------------------------------------- review */

    public function test_accountant_approves_and_it_is_audited_with_their_role(): void
    {
        Sanctum::actingAs($this->accountant);

        $this->postJson("/api/payments/{$this->payB->id}/review", ['action' => 'approved'])->assertOk();

        $fresh = $this->payB->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertSame($this->accountant->id, $fresh->reviewed_by);

        $log = AuditLog::where('action', 'payment.approved')->where('auditable_id', $this->payB->id)->firstOrFail();
        $this->assertSame($this->accountant->id, $log->user_id);
        $this->assertSame(User::ROLE_ACCOUNTANT, $log->metadata['by_role']);
    }

    public function test_rejecting_needs_a_reason_for_everyone_who_can_reject(): void
    {
        foreach ([$this->accountant, $this->superAdmin] as $reviewer) {
            Sanctum::actingAs($reviewer);

            $this->postJson("/api/payments/{$this->payA->id}/review", ['action' => 'rejected'])
                ->assertStatus(422)->assertJsonValidationErrors('rejection_reason');
            $this->postJson("/api/payments/{$this->payA->id}/review", ['action' => 'rejected', 'rejection_reason' => 'abc'])
                ->assertStatus(422);
            $this->assertSame('pending', $this->payA->fresh()->status);
        }

        Sanctum::actingAs($this->accountant);
        $this->postJson("/api/payments/{$this->payA->id}/review", ['action' => 'rejected', 'rejection_reason' => 'الإيصال غير واضح'])->assertOk();

        $fresh = $this->payA->fresh();
        $this->assertSame('rejected', $fresh->status);
        $this->assertSame('الإيصال غير واضح', $fresh->rejection_reason);
        $log = AuditLog::where('action', 'payment.rejected')->where('auditable_id', $this->payA->id)->firstOrFail();
        $this->assertSame(User::ROLE_ACCOUNTANT, $log->metadata['by_role']);
        $this->assertSame('الإيصال غير واضح', $log->metadata['reason']);
    }

    public function test_the_reason_may_still_arrive_as_notes_like_the_apps_send_it(): void
    {
        Sanctum::actingAs($this->accountant);

        $this->postJson("/api/payments/{$this->payA->id}/review", ['action' => 'rejected', 'notes' => 'المبلغ لا يطابق العقد'])->assertOk();
        $this->assertSame('المبلغ لا يطابق العقد', $this->payA->fresh()->rejection_reason);
    }

    public function test_an_account_manager_can_no_longer_review_a_payment(): void
    {
        Sanctum::actingAs($this->managerA);

        $this->postJson("/api/payments/{$this->payA->id}/review", ['action' => 'approved'])->assertForbidden();
        $this->postJson("/api/payments/{$this->payA->id}/review", ['action' => 'rejected', 'rejection_reason' => 'سبب كافي'])->assertForbidden();
        $this->assertSame('pending', $this->payA->fresh()->status);
    }

    public function test_the_second_reviewer_of_the_same_payment_gets_a_conflict(): void
    {
        Sanctum::actingAs($this->accountant);
        $this->postJson("/api/payments/{$this->payA->id}/review", ['action' => 'approved'])->assertOk();

        Sanctum::actingAs($this->superAdmin);
        $this->postJson("/api/payments/{$this->payA->id}/review", ['action' => 'rejected', 'rejection_reason' => 'سبب كافي'])->assertStatus(409);

        $this->assertSame('approved', $this->payA->fresh()->status);
        $this->assertSame($this->accountant->id, $this->payA->fresh()->reviewed_by);
    }

    public function test_the_accountant_cannot_review_an_archived_clients_payment_but_the_super_admin_can(): void
    {
        $this->clientA->update(['status' => 'archived']);

        Sanctum::actingAs($this->accountant);
        $this->postJson("/api/payments/{$this->payA->id}/review", ['action' => 'approved'])->assertStatus(422);
        $this->assertSame('pending', $this->payA->fresh()->status);

        Sanctum::actingAs($this->superAdmin);
        $this->postJson("/api/payments/{$this->payA->id}/review", ['action' => 'approved'])->assertOk();
    }

    public function test_the_client_never_sees_who_reviewed_their_payment(): void
    {
        Sanctum::actingAs($this->accountant);
        $this->postJson("/api/payments/{$this->payA->id}/review", ['action' => 'rejected', 'rejection_reason' => 'الإيصال غير واضح'])->assertOk();

        $this->app->make('auth')->forgetGuards();
        $body = json_encode(
            $this->actingAs($this->clientA, 'client')->getJson("/api/workspaces/{$this->wsA->id}/payments")->assertOk()->json(),
            JSON_UNESCAPED_UNICODE
        );

        $this->assertStringContainsString('الإيصال غير واضح', $body);
        $this->assertStringNotContainsString($this->accountant->name, $body);
        $this->assertStringNotContainsString($this->accountant->email, $body);
    }

    /* --------------------------------------------------------- notifications */

    public function test_a_new_payment_proof_notifies_every_active_accountant_and_a_deactivated_one_is_skipped(): void
    {
        $second = User::factory()->create(['role' => User::ROLE_ACCOUNTANT, 'super_admin_id' => $this->superAdmin->id, 'is_active' => true]);
        $off = User::factory()->create(['role' => User::ROLE_ACCOUNTANT, 'super_admin_id' => $this->superAdmin->id, 'is_active' => false]);
        Notification::fake();

        $payment = Payment::factory()->create(['workspace_id' => $this->wsA->id, 'client_id' => $this->clientA->id, 'status' => 'pending']);
        \App\Events\PaymentCreated::dispatch($payment);

        Notification::assertSentTo($this->accountant, PaymentCreatedNotification::class);
        Notification::assertSentTo($second, PaymentCreatedNotification::class);
        Notification::assertNotSentTo($off, PaymentCreatedNotification::class);
    }

    public function test_an_accountants_decision_tells_the_manager_and_the_other_accountants_but_not_themselves(): void
    {
        $second = User::factory()->create(['role' => User::ROLE_ACCOUNTANT, 'super_admin_id' => $this->superAdmin->id, 'is_active' => true]);
        Notification::fake();
        Sanctum::actingAs($this->accountant);

        $this->postJson("/api/payments/{$this->payA->id}/review", ['action' => 'approved'])->assertOk();

        Notification::assertSentTo($this->managerA, PaymentReviewedStaffNotification::class);
        Notification::assertSentTo($second, PaymentReviewedStaffNotification::class);
        Notification::assertNotSentTo($this->accountant, PaymentReviewedStaffNotification::class);
        Notification::assertNotSentTo($this->managerB, PaymentReviewedStaffNotification::class);
    }

    public function test_the_super_admins_decision_tells_the_accountants_only(): void
    {
        Notification::fake();
        Sanctum::actingAs($this->superAdmin);

        $this->postJson("/api/payments/{$this->payA->id}/review", ['action' => 'approved'])->assertOk();

        Notification::assertSentTo($this->accountant, PaymentReviewedStaffNotification::class);
        Notification::assertNotSentTo($this->managerA, PaymentReviewedStaffNotification::class);
    }

    /* ----------------------------------------------------------------- export */

    public function test_export_contains_every_matching_row_not_just_the_first_page(): void
    {
        Payment::factory()->count(34)->create(['workspace_id' => $this->wsA->id, 'client_id' => $this->clientA->id, 'status' => 'pending', 'currency' => 'SAR']);
        Sanctum::actingAs($this->accountant);

        $csv = $this->get('/api/all-payments/export')->assertOk()->streamedContent();
        $lines = array_values(array_filter(preg_split('/\r\n/', $csv)));

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertCount(1 + 36, $lines); // header + 36 payments (2 from setUp)
        $this->assertStringContainsString('"Client"', $lines[0]);

        $usd = $this->get('/api/all-payments/export?currency=USD')->assertOk()->streamedContent();
        $this->assertCount(1 + 1, array_values(array_filter(preg_split('/\r\n/', $usd))));
    }

    public function test_a_manager_exports_only_their_own_clients_payments(): void
    {
        Sanctum::actingAs($this->managerA);

        $csv = $this->get('/api/all-payments/export')->assertOk()->streamedContent();

        $this->assertCount(1 + 1, array_values(array_filter(preg_split('/\r\n/', $csv))));
    }

    public function test_export_neutralises_spreadsheet_formulas(): void
    {
        $this->clientA->update(['company_name' => '=HYPERLINK("http://evil","x")']);
        Sanctum::actingAs($this->accountant);

        $csv = $this->get('/api/all-payments/export')->assertOk()->streamedContent();

        $this->assertStringContainsString('"\'=HYPERLINK(""http://evil"",""x"")"', $csv);
        $this->assertStringNotContainsString(',"=HYPERLINK', $csv);
    }

    public function test_every_export_is_written_to_the_audit_log(): void
    {
        Sanctum::actingAs($this->accountant);
        $this->get('/api/all-payments/export?currency=SAR')->assertOk()->streamedContent();

        $log = AuditLog::where('action', 'payments.exported')->firstOrFail();
        $this->assertSame($this->accountant->id, $log->user_id);
        $this->assertSame(1, $log->metadata['rows']);
        $this->assertSame('SAR', $log->metadata['filters']['currency']);
        $this->assertSame(User::ROLE_ACCOUNTANT, $log->metadata['by_role']);
    }

    public function test_a_client_token_cannot_use_the_company_lists(): void
    {
        // A real bearer token: actingAs($client, 'client') never reaches auth:sanctum routes.
        $token = $this->clientA->createToken('test')->plainTextToken;

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])->getJson('/api/all-payments/export')->assertForbidden();
    }

    /* ------------------------------------------------------------- management */

    public function test_only_the_super_admin_manages_accountants(): void
    {
        Sanctum::actingAs($this->superAdmin);
        $created = $this->postJson('/api/accountants', [
            'name' => 'Mona Accountant', 'email' => 'mona@example.com', 'password' => 'Secret123', 'send_email' => false,
        ])->assertCreated()->json('accountant');

        $this->assertSame(User::ROLE_ACCOUNTANT, $created['role']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'accountant.created', 'user_id' => $this->superAdmin->id]);
        $this->getJson('/api/accountants')->assertOk()->assertJsonCount(2, 'accountants');

        foreach ([$this->managerA, $this->accountant] as $other) {
            Sanctum::actingAs($other);
            $this->getJson('/api/accountants')->assertForbidden();
            $this->postJson('/api/accountants', ['name' => 'x', 'email' => 'x@example.com', 'password' => 'Secret123'])->assertForbidden();
            $this->putJson("/api/accountants/{$created['id']}", ['name' => 'Hacked'])->assertForbidden();
            $this->postJson("/api/accountants/{$created['id']}/deactivate")->assertForbidden();
        }
        $this->assertSame('Mona Accountant', User::find($created['id'])->name);
    }

    public function test_an_accountant_id_is_the_only_id_these_routes_accept(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $this->putJson("/api/accountants/{$this->managerA->id}", ['name' => 'Hijack'])->assertNotFound();
        $this->postJson("/api/accountants/{$this->managerA->id}/deactivate")->assertNotFound();
        $this->assertTrue($this->managerA->fresh()->is_active);
    }

    public function test_deactivating_cuts_sessions_and_keeps_the_name_for_the_audit_trail(): void
    {
        $this->accountant->createToken('t');
        Sanctum::actingAs($this->superAdmin);

        $this->postJson("/api/accountants/{$this->accountant->id}/deactivate")->assertOk()->assertJsonPath('accountant.is_active', false);

        $this->assertSame(0, $this->accountant->tokens()->count());
        $this->assertNotNull(User::find($this->accountant->id));

        $this->postJson("/api/accountants/{$this->accountant->id}/activate")->assertOk()->assertJsonPath('accountant.is_active', true);
    }

    public function test_an_accountant_can_sign_in_and_gets_their_role_back(): void
    {
        $this->accountant->update(['password' => 'Secret123']);

        $this->postJson('/api/auth/login', ['email' => $this->accountant->email, 'password' => 'Secret123'])
            ->assertOk()->assertJsonPath('user.role', 'accountant');
    }

    public function test_the_emails_of_accountants_are_unique_across_every_account_type(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $this->postJson('/api/accountants', [
            'name' => 'Dup', 'email' => $this->clientA->email, 'password' => 'Secret123', 'send_email' => false,
        ])->assertStatus(422);
    }

    public function test_str_helper_sanity(): void
    {
        // Guards the URI-template replacement the route walk relies on.
        $this->assertSame('api/payments/1/review', preg_replace('/\{[^}]+\}/', '1', 'api/payments/{payment}/review'));
        $this->assertTrue(Str::is('api/notifications/*', 'api/notifications/1/read'));
    }
}
