<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\FileEntry;
use App\Models\Payment;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Manager assistants (MANAGER_ASSISTANT_PLAN.md م٢): a staff user bound to one
 * account manager, limited to that manager's clients, with a permission set
 * the manager chose and a hard wall around everything financial/admin.
 */
class ManagerAssistantAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private User $otherManager;
    private Client $client;
    private Client $otherClient;
    private Workspace $workspace;
    private Workspace $otherWorkspace;
    private Contract $contract;
    private Contract $otherContract;
    private Payment $payment;
    private FileEntry $file;

    protected function setUp(): void
    {
        parent::setUp();

        User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $this->manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $this->otherManager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);

        $this->client = Client::factory()->create(['manager_id' => $this->manager->id]);
        $this->otherClient = Client::factory()->create(['manager_id' => $this->otherManager->id]);
        $this->workspace = Workspace::factory()->create(['client_id' => $this->client->id, 'manager_id' => $this->manager->id, 'status' => 'active']);
        $this->otherWorkspace = Workspace::factory()->create(['client_id' => $this->otherClient->id, 'manager_id' => $this->otherManager->id, 'status' => 'active']);
        $this->contract = Contract::factory()->create(['workspace_id' => $this->workspace->id, 'status' => 'sent']);
        $this->otherContract = Contract::factory()->create(['workspace_id' => $this->otherWorkspace->id, 'status' => 'sent']);
        $this->payment = Payment::factory()->create(['workspace_id' => $this->workspace->id, 'client_id' => $this->client->id]);
        $this->file = FileEntry::create([
            'workspace_id' => $this->workspace->id,
            'uploaded_by_type' => Client::class,
            'uploaded_by_id' => $this->client->id,
            'file_url' => '/storage/doc.pdf',
            'name' => 'doc.pdf',
            'status' => 'pending',
        ]);
    }

    private function assistant(array $permissions = [], ?User $parent = null): User
    {
        return User::factory()->create([
            'role' => User::ROLE_MANAGER_ASSISTANT,
            'parent_manager_id' => ($parent ?? $this->manager)->id,
            'assistant_permissions' => array_fill_keys($permissions, true),
        ]);
    }

    private function allPermissions(): array
    {
        return User::ASSISTANT_PERMISSION_KEYS;
    }

    // ── ت١ — every financial / admin route is walled off, even with every permission ──

    public static function forbiddenRoutes(): array
    {
        return [
            'all payments' => ['GET', '/api/all-payments'],
            'pending payments' => ['GET', '/api/payments/pending'],
            'workspace payments' => ['GET', '/api/workspaces/{w}/payments'],
            'payment schedule' => ['GET', '/api/workspaces/{w}/payment-schedule'],
            'tax summary' => ['GET', '/api/settings/tax-summary/{w}'],
            'reports' => ['GET', '/api/reports'],
            'audit logs' => ['GET', '/api/audit-logs'],
            'login attempts' => ['GET', '/api/login-attempts'],
            'account managers' => ['GET', '/api/account-managers'],
            'review payment' => ['POST', '/api/payments/{p}/review'],
            'upload payment' => ['POST', '/api/workspaces/{w}/payments'],
            'schedule payment' => ['POST', '/api/workspaces/{w}/payments/schedule'],
            'request payment' => ['POST', '/api/workspaces/{w}/payments/request'],
            'update schedule' => ['PUT', '/api/payments/{p}/schedule'],
            'delete schedule' => ['DELETE', '/api/payments/{p}/schedule'],
            'company approve' => ['POST', '/api/contracts/{c}/company-approve'],
            'complete contract' => ['POST', '/api/contracts/{c}/complete'],
            'archive contract' => ['POST', '/api/contracts/{c}/archive'],
            'create client' => ['POST', '/api/clients'],
            'transfer client' => ['POST', '/api/clients/{cl}/transfer'],
            'archive client' => ['POST', '/api/clients/{cl}/archive'],
            'unarchive client' => ['POST', '/api/clients/{cl}/unarchive'],
            'create workspace' => ['POST', '/api/workspaces'],
            'activate workspace' => ['POST', '/api/workspaces/{w}/activate'],
            'update settings' => ['PUT', '/api/settings'],
            'create clause template' => ['POST', '/api/contract-clause-templates'],
            'send fcm' => ['POST', '/api/notifications/send-fcm'],
        ];
    }

    #[DataProvider('forbiddenRoutes')]
    public function test_an_assistant_with_every_permission_is_refused_on_financial_and_admin_routes(string $method, string $url): void
    {
        Sanctum::actingAs($this->assistant($this->allPermissions()));

        $url = strtr($url, [
            '{w}' => $this->workspace->id,
            '{p}' => $this->payment->id,
            '{c}' => $this->contract->id,
            '{cl}' => $this->client->id,
        ]);

        $this->json($method, $url, [])->assertForbidden();
    }

    // ── ت٢ — isolation from another manager's data ──

    public function test_an_assistant_cannot_reach_another_managers_data(): void
    {
        Sanctum::actingAs($this->assistant($this->allPermissions()));

        $attempts = [
            ['GET', "/api/clients/{$this->otherClient->id}"],
            ['PUT', "/api/clients/{$this->otherClient->id}"],
            ['GET', "/api/workspaces/{$this->otherWorkspace->id}"],
            ['GET', "/api/workspaces/{$this->otherWorkspace->id}/chat"],
            ['GET', "/api/workspaces/{$this->otherWorkspace->id}/files"],
            ['POST', "/api/workspaces/{$this->otherWorkspace->id}/contracts"],
            ['POST', "/api/workspaces/{$this->otherWorkspace->id}/meetings"],
            ['GET', "/api/contracts/{$this->otherContract->id}"],
            ['PUT', "/api/contracts/{$this->otherContract->id}"],
            ['POST', "/api/contracts/{$this->otherContract->id}/send"],
        ];

        foreach ($attempts as [$method, $url]) {
            $status = $this->json($method, $url, ['title' => 'x'])->status();
            $this->assertContains($status, [403, 404], "$method $url answered $status");
        }
    }

    public function test_an_assistant_lists_only_their_managers_data(): void
    {
        Sanctum::actingAs($this->assistant($this->allPermissions()));

        $this->getJson('/api/clients')->assertOk()->assertJsonCount(1, 'clients.data')
            ->assertJsonPath('clients.data.0.id', $this->client->id);
        $this->getJson('/api/all-contracts')->assertOk()->assertJsonCount(1, 'contracts.data');
    }

    public function test_an_assistant_reads_their_own_managers_client(): void
    {
        Sanctum::actingAs($this->assistant());

        $this->getJson("/api/clients/{$this->client->id}")->assertOk();
        $this->getJson("/api/workspaces/{$this->workspace->id}")->assertOk();
        $this->getJson("/api/contracts/{$this->contract->id}")->assertOk();
    }

    // ── ت٤ — every permission works on its own ──

    public static function permissionRoutes(): array
    {
        return [
            'edit clients' => ['can_edit_clients', 'PUT', '/api/clients/{cl}', ['contact_person' => 'New Name']],
            'chat' => ['can_chat', 'POST', '/api/workspaces/{w}/chat', ['message' => 'hello']],
            'manage contracts' => ['can_manage_contracts', 'POST', '/api/workspaces/{w}/contracts', ['title' => 'Extra']],
            'manage meetings' => ['can_manage_meetings', 'POST', '/api/workspaces/{w}/meetings', []],
            'view files' => ['can_view_files', 'GET', '/api/workspaces/{w}/files', []],
            'review files' => ['can_review_files', 'POST', '/api/files/{f}/review', ['action' => 'approved']],
            'manage approvals' => ['can_manage_approvals', 'POST', '/api/workspaces/{w}/approvals', []],
        ];
    }

    private function permissionUrl(string $url): string
    {
        return strtr($url, [
            '{w}' => $this->workspace->id,
            '{cl}' => $this->client->id,
            '{f}' => $this->file->id,
        ]);
    }

    #[DataProvider('permissionRoutes')]
    public function test_without_the_permission_the_action_is_refused(string $permission, string $method, string $url, array $body): void
    {
        SystemSetting::setValue('managers_can_review_files', '1');
        $others = array_values(array_diff($this->allPermissions(), [$permission]));
        Sanctum::actingAs($this->assistant($others));

        $this->json($method, $this->permissionUrl($url), $body)->assertForbidden();
    }

    #[DataProvider('permissionRoutes')]
    public function test_with_the_permission_the_action_is_not_refused(string $permission, string $method, string $url, array $body): void
    {
        SystemSetting::setValue('managers_can_review_files', '1');
        Sanctum::actingAs($this->assistant([$permission]));

        $status = $this->json($method, $this->permissionUrl($url), $body)->status();
        $this->assertNotEquals(403, $status, "$permission was still refused");
    }

    public function test_a_contract_created_by_an_assistant_belongs_to_their_manager(): void
    {
        $assistant = $this->assistant(['can_manage_contracts']);
        Sanctum::actingAs($assistant);

        $this->postJson("/api/workspaces/{$this->workspace->id}/contracts", ['title' => 'By assistant', 'value' => 1200])->assertCreated();

        $this->assertDatabaseHas('contracts', [
            'title' => 'By assistant',
            'workspace_id' => $this->workspace->id,
            'created_by' => $assistant->id,
        ]);
    }

    public function test_review_files_is_still_subject_to_the_managers_review_setting(): void
    {
        SystemSetting::setValue('managers_can_review_files', '0');
        Sanctum::actingAs($this->assistant(['can_review_files']));

        $this->postJson("/api/files/{$this->file->id}/review", ['action' => 'approved'])->assertForbidden();
    }

    // ── ت١٤ — no money keys in the dashboard numbers ──

    public function test_dashboard_stats_for_an_assistant_have_no_payment_or_revenue_keys(): void
    {
        Sanctum::actingAs($this->assistant($this->allPermissions()));

        $this->getJson('/api/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('clients.total', 1)
            ->assertJsonMissingPath('payments')
            ->assertJsonMissingPath('revenue_this_month');
    }

    public function test_the_managers_own_stats_still_have_them(): void
    {
        Sanctum::actingAs($this->manager);

        $this->getJson('/api/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('payments.pending', 1)
            ->assertJsonStructure(['revenue_this_month']);
    }

    public function test_pending_approvals_for_an_assistant_never_list_payments(): void
    {
        Sanctum::actingAs($this->assistant($this->allPermissions()));

        $this->getJson('/api/dashboard/pending-approvals')
            ->assertOk()
            ->assertJsonCount(0, 'awaiting_you.payments');
    }

    // ── lifecycle basics used by later phases ──

    public function test_an_assistant_is_active_only_while_their_manager_is(): void
    {
        // fresh(): the factory doesn't set is_active, so reload the DB default.
        $assistant = $this->assistant()->fresh();
        $this->assertTrue($assistant->isActive());

        $this->manager->update(['is_active' => false]);
        $this->assertFalse($assistant->fresh()->isActive());

        $this->manager->update(['is_active' => true]);
        $assistant->update(['is_active' => false]);
        $this->assertFalse($assistant->fresh()->isActive());
    }

    public function test_assistant_helpers(): void
    {
        $assistant = $this->assistant(['can_chat']);

        $this->assertTrue($assistant->isAssistant());
        $this->assertFalse($assistant->isAccountManager());
        $this->assertSame($this->manager->id, $assistant->ownerManagerId());
        $this->assertTrue($assistant->assistantCan('can_chat'));
        $this->assertTrue($assistant->assistantCan('can_view_clients'));
        $this->assertFalse($assistant->assistantCan('can_manage_contracts'));
        $this->assertTrue($this->manager->assistantCan('can_manage_contracts'));
        $this->assertSame($this->manager->id, $assistant->parentManager->id);
        $this->assertCount(1, $this->manager->assistants);
    }
}
