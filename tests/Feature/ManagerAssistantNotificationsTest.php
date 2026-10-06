<?php

namespace Tests\Feature;

use App\Mail\ContractSentMail;
use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Payment;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\ApprovalRequestedNotification;
use App\Notifications\ContractSentNotification;
use App\Notifications\PaymentCreatedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Assistant notifications, audit visibility and stats (MANAGER_ASSISTANT_PLAN.md م٤).
 */
class ManagerAssistantNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private User $manager;
    private User $otherManager;
    private Client $client;
    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $this->manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER, 'super_admin_id' => $this->superAdmin->id, 'is_active' => true]);
        $this->otherManager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER, 'super_admin_id' => $this->superAdmin->id, 'is_active' => true]);
        $this->client = Client::factory()->create(['manager_id' => $this->manager->id]);
        $this->workspace = Workspace::factory()->create(['client_id' => $this->client->id, 'manager_id' => $this->manager->id, 'status' => 'inactive']);
    }

    private function assistant(array $permissions = [], ?User $parent = null, array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'role' => User::ROLE_MANAGER_ASSISTANT,
            'parent_manager_id' => ($parent ?? $this->manager)->id,
            'assistant_permissions' => array_fill_keys($permissions, true),
            'is_active' => true,
        ], $attrs));
    }

    // ── ت١٢ — notifications follow permissions, never payments ──

    public function test_a_contract_notification_reaches_only_assistants_who_manage_contracts(): void
    {
        Notification::fake();
        $with = $this->assistant(['can_manage_contracts']);
        $without = $this->assistant(['can_chat'], null, ['email' => 'w@example.com']);
        $contract = Contract::factory()->create(['workspace_id' => $this->workspace->id, 'status' => 'sent']);

        $this->manager->notify(new ContractSentNotification($contract));

        Notification::assertSentTo($this->manager, ContractSentNotification::class);
        Notification::assertSentTo($with, ContractSentNotification::class);
        Notification::assertNotSentTo($without, ContractSentNotification::class);
    }

    public function test_a_payment_notification_never_reaches_an_assistant(): void
    {
        Notification::fake();
        $assistant = $this->assistant(User::ASSISTANT_PERMISSION_KEYS);
        $payment = Payment::factory()->create(['workspace_id' => $this->workspace->id, 'client_id' => $this->client->id]);

        $this->manager->notify(new PaymentCreatedNotification($payment));

        Notification::assertSentTo($this->manager, PaymentCreatedNotification::class);
        Notification::assertNotSentTo($assistant, PaymentCreatedNotification::class);
    }

    public function test_approval_notifications_follow_manage_approvals(): void
    {
        Notification::fake();
        $with = $this->assistant(['can_manage_approvals']);
        $without = $this->assistant([], null, ['email' => 'w@example.com']);
        $approval = Approval::factory()->create(['workspace_id' => $this->workspace->id, 'requested_by' => $this->superAdmin->id]);

        $this->manager->notify(new ApprovalRequestedNotification($approval));

        Notification::assertSentTo($with, ApprovalRequestedNotification::class);
        Notification::assertNotSentTo($without, ApprovalRequestedNotification::class);
    }

    public function test_inactive_assistants_and_other_managers_assistants_get_nothing(): void
    {
        Notification::fake();
        $inactive = $this->assistant(['can_manage_contracts'], null, ['is_active' => false, 'email' => 'i@example.com']);
        $foreign = $this->assistant(['can_manage_contracts'], $this->otherManager, ['email' => 'f@example.com']);
        $contract = Contract::factory()->create(['workspace_id' => $this->workspace->id, 'status' => 'sent']);

        $this->manager->notify(new ContractSentNotification($contract));

        Notification::assertNotSentTo($inactive, ContractSentNotification::class);
        Notification::assertNotSentTo($foreign, ContractSentNotification::class);
    }

    public function test_an_assistants_own_notification_does_not_fan_out_further(): void
    {
        Notification::fake();
        $assistant = $this->assistant(['can_manage_contracts']);
        $contract = Contract::factory()->create(['workspace_id' => $this->workspace->id, 'status' => 'sent']);

        $assistant->notify(new ContractSentNotification($contract));

        Notification::assertSentToTimes($assistant, ContractSentNotification::class, 1);
        Notification::assertNotSentTo($this->manager, ContractSentNotification::class);
    }

    public function test_the_assistant_reads_their_own_notifications_through_the_api(): void
    {
        $assistant = $this->assistant(['can_manage_contracts']);
        $contract = Contract::factory()->create(['workspace_id' => $this->workspace->id, 'status' => 'sent']);
        $payment = Payment::factory()->create(['workspace_id' => $this->workspace->id, 'client_id' => $this->client->id]);

        $this->manager->notify(new ContractSentNotification($contract));
        $this->manager->notify(new PaymentCreatedNotification($payment));

        Sanctum::actingAs($assistant);
        $response = $this->getJson('/api/notifications')->assertOk();

        $types = array_column($response->json('notifications'), 'data');
        $types = array_map(fn ($d) => $d['type'] ?? null, $types);
        $this->assertContains('contract_sent', $types);
        $this->assertNotContains('payment_created', $types);
    }

    // ── what an assistant creates is the manager's to be told about ──

    public function test_a_contract_sent_by_an_assistant_notifies_and_emails_the_manager_not_the_assistant(): void
    {
        Mail::fake();
        Notification::fake();
        $assistant = $this->assistant(['can_manage_contracts']);
        Sanctum::actingAs($assistant);

        $created = $this->postJson("/api/workspaces/{$this->workspace->id}/contracts", ['title' => 'By assistant', 'value' => 1000])->assertCreated();
        $this->postJson('/api/contracts/' . $created->json('contract.id') . '/send')->assertOk();

        Notification::assertSentTo($this->manager, ContractSentNotification::class);
        Mail::assertQueued(ContractSentMail::class, fn ($mail) => $mail->hasTo($this->manager->email));
        Mail::assertNotQueued(ContractSentMail::class, fn ($mail) => $mail->hasTo($assistant->email));
    }

    // ── ت١١ — audit ──

    public function test_the_audit_log_names_the_assistant_and_whose_team_they_are_on(): void
    {
        $assistant = $this->assistant(['can_manage_contracts'], null, ['name' => 'Sami']);
        $outsider = $this->assistant([], $this->otherManager, ['email' => 'o@example.com', 'name' => 'Other']);
        AuditLog::create(['auditable_type' => Client::class, 'auditable_id' => $this->client->id, 'client_id' => $this->client->id, 'user_id' => $assistant->id, 'action' => 'contract.sent', 'ip_address' => '1.1.1.1']);
        AuditLog::create(['auditable_type' => User::class, 'auditable_id' => $assistant->id, 'user_id' => $assistant->id, 'action' => 'auth.login', 'ip_address' => '1.1.1.1']);
        AuditLog::create(['auditable_type' => User::class, 'auditable_id' => $outsider->id, 'user_id' => $outsider->id, 'action' => 'auth.login', 'ip_address' => '1.1.1.1']);

        Sanctum::actingAs($this->manager);
        $logs = $this->getJson('/api/audit-logs')->assertOk()->json('logs.data');

        $mine = array_values(array_filter($logs, fn ($l) => ($l['user']['id'] ?? null) === $assistant->id));
        $this->assertCount(2, $mine);
        $this->assertSame($assistant->id, $mine[0]['user_id']);
        $this->assertSame($this->manager->name, $mine[0]['user']['assistant_of']);
        $this->assertEmpty(array_filter($logs, fn ($l) => ($l['user']['id'] ?? null) === $outsider->id), "another team's logs must not leak");
    }

    public function test_the_real_assistant_id_is_what_gets_audited(): void
    {
        $assistant = $this->assistant(['can_manage_contracts']);
        Sanctum::actingAs($assistant);

        $this->postJson("/api/workspaces/{$this->workspace->id}/contracts", ['title' => 'Audited'])->assertCreated();

        $this->assertDatabaseHas('contracts', ['title' => 'Audited', 'created_by' => $assistant->id]);
    }

    // ── ت١٦ — stats follow the workspace, not created_by ──

    public function test_contracts_an_assistant_created_count_for_their_manager(): void
    {
        $assistant = $this->assistant(['can_manage_contracts']);
        Contract::factory()->create(['workspace_id' => $this->workspace->id, 'status' => 'company_approved', 'created_by' => $assistant->id]);

        Sanctum::actingAs($this->manager);
        $this->getJson('/api/dashboard/stats')->assertOk()->assertJsonPath('contracts.active', 1);

        $stats = $this->getJson('/api/reports')->assertOk()->json('manager_stats');
        $row = collect($stats)->firstWhere('id', $this->manager->id);
        $this->assertSame(1, $row['contracts']);

        // and the assistant is nowhere in the managers' leaderboard
        Sanctum::actingAs($this->superAdmin);
        $ids = array_column($this->getJson('/api/reports')->assertOk()->json('manager_stats'), 'id');
        $this->assertNotContains($assistant->id, $ids);
        $this->assertContains($this->manager->id, $ids);
    }
}
