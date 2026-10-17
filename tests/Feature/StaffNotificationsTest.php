<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ChatMessage;
use App\Models\Client;
use App\Models\Meeting;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\ApprovalRespondedNotification;
use App\Notifications\ChatMessageSentNotification;
use App\Notifications\MeetingReminderNotification;
use App\Notifications\StaffAssignedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase ف٤ (STAFF_TEAMS_PLAN.md §٤): what reaches company staff, and only for
 * the clients they are assigned to — a client's chat message, a client's
 * answer to an approval request, a meeting reminder, being assigned.
 */
class StaffNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private User $manager;
    private User $staff;
    private User $otherStaff;
    private User $unassigned;
    private Client $client;
    private Workspace $ws;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $this->manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER, 'super_admin_id' => $this->superAdmin->id, 'is_active' => true]);
        $this->staff = $this->member('Huda');
        $this->otherStaff = $this->member('Omar');
        $this->unassigned = $this->member('Nour');

        $this->client = Client::factory()->create(['manager_id' => $this->manager->id, 'signature_data' => 'Client Sig']);
        $this->ws = Workspace::factory()->create(['client_id' => $this->client->id, 'manager_id' => $this->manager->id, 'status' => 'active']);

        $this->ws->staff()->attach([$this->staff->id => ['assigned_by' => $this->superAdmin->id], $this->otherStaff->id => ['assigned_by' => $this->superAdmin->id]]);
    }

    private function member(string $name): User
    {
        return User::factory()->create(['name' => $name, 'role' => User::ROLE_STAFF_MEMBER, 'is_active' => true, 'password' => 'Password123']);
    }

    // ── chat ──

    public function test_a_client_message_reaches_the_assigned_staff_and_the_manager(): void
    {
        Notification::fake();
        Sanctum::actingAs($this->client);

        $this->postJson("/api/workspaces/{$this->ws->id}/chat", ['message' => 'hello team'])->assertCreated();

        Notification::assertSentTo($this->manager, ChatMessageSentNotification::class);
        Notification::assertSentTo($this->staff, ChatMessageSentNotification::class);
        Notification::assertSentTo($this->otherStaff, ChatMessageSentNotification::class);
        Notification::assertNotSentTo($this->unassigned, ChatMessageSentNotification::class);
    }

    public function test_an_archived_staff_member_gets_nothing(): void
    {
        Notification::fake();
        $this->otherStaff->forceFill(['is_active' => false, 'archived_at' => now()])->save();
        Sanctum::actingAs($this->client);

        $this->postJson("/api/workspaces/{$this->ws->id}/chat", ['message' => 'hello'])->assertCreated();

        Notification::assertSentTo($this->staff, ChatMessageSentNotification::class);
        Notification::assertNotSentTo($this->otherStaff, ChatMessageSentNotification::class);
    }

    public function test_staff_and_manager_messages_do_not_notify_other_staff(): void
    {
        Notification::fake();

        Sanctum::actingAs($this->staff);
        $this->postJson("/api/workspaces/{$this->ws->id}/chat", ['message' => 'from staff'])->assertCreated();

        Sanctum::actingAs($this->manager);
        $this->postJson("/api/workspaces/{$this->ws->id}/chat", ['message' => 'from manager'])->assertCreated();

        Notification::assertNotSentTo($this->staff, ChatMessageSentNotification::class);
        Notification::assertNotSentTo($this->otherStaff, ChatMessageSentNotification::class);
        // The client is the one who is told.
        Notification::assertSentTo($this->client, ChatMessageSentNotification::class);
    }

    public function test_a_second_message_within_five_minutes_does_not_push_again_but_is_still_stored(): void
    {
        Sanctum::actingAs($this->client);

        $this->postJson("/api/workspaces/{$this->ws->id}/chat", ['message' => 'one'])->assertCreated();
        $this->postJson("/api/workspaces/{$this->ws->id}/chat", ['message' => 'two'])->assertCreated();

        $this->assertSame(2, $this->staff->notifications()->where('data->type', 'chat')->count());
    }

    // ── approvals ──

    public function test_the_client_answering_an_approval_request_reaches_the_staff(): void
    {
        Mail::fake();
        Sanctum::actingAs($this->staff);
        $this->postJson("/api/workspaces/{$this->ws->id}/approvals", ['title' => 'Approve the logo'])->assertCreated();
        $messageId = ChatMessage::where('workspace_id', $this->ws->id)->where('requires_action', true)->value('id');

        Notification::fake();
        Sanctum::actingAs($this->client);
        $this->postJson("/api/chat/{$messageId}/respond", ['action' => 'edit_requested', 'reason' => 'change colour'])->assertOk();

        // The one who raised it is told once (not twice), the other staff member too,
        // and someone not on this client is not.
        Notification::assertSentToTimes($this->staff, ApprovalRespondedNotification::class, 1);
        Notification::assertSentToTimes($this->otherStaff, ApprovalRespondedNotification::class, 1);
        Notification::assertNotSentTo($this->unassigned, ApprovalRespondedNotification::class);
    }

    public function test_when_the_manager_raised_the_request_every_assigned_staff_hears_the_answer(): void
    {
        Mail::fake();
        Sanctum::actingAs($this->manager);
        $this->postJson("/api/workspaces/{$this->ws->id}/approvals", ['title' => 'Budget'])->assertCreated();
        $messageId = ChatMessage::where('workspace_id', $this->ws->id)->where('requires_action', true)->value('id');

        Notification::fake();
        Sanctum::actingAs($this->client);
        $this->postJson("/api/chat/{$messageId}/respond", ['action' => 'edit_requested'])->assertOk();

        Notification::assertSentTo($this->manager, ApprovalRespondedNotification::class);
        Notification::assertSentTo($this->staff, ApprovalRespondedNotification::class);
        Notification::assertSentTo($this->otherStaff, ApprovalRespondedNotification::class);
        Notification::assertNotSentTo($this->unassigned, ApprovalRespondedNotification::class);
    }

    // ── meetings ──

    public function test_meeting_reminders_reach_the_assigned_staff(): void
    {
        Notification::fake();
        Meeting::create([
            'workspace_id' => $this->ws->id,
            'title' => 'Kick-off',
            'scheduled_at' => now()->addMinutes(20),
            'duration_minutes' => 30,
            'status' => 'scheduled',
            'created_by' => $this->manager->id,
            'link' => 'https://zoom.us/j/1',
        ]);

        $this->artisan('meetings:send-reminders')->assertSuccessful();

        Notification::assertSentToTimes($this->manager, MeetingReminderNotification::class, 1);
        Notification::assertSentTo($this->client, MeetingReminderNotification::class);
        Notification::assertSentToTimes($this->staff, MeetingReminderNotification::class, 1);
        Notification::assertSentToTimes($this->otherStaff, MeetingReminderNotification::class, 1);
        Notification::assertNotSentTo($this->unassigned, MeetingReminderNotification::class);
    }

    // ── assignment ──

    public function test_being_assigned_notifies_the_staff_member_but_removal_and_repeats_do_not(): void
    {
        Notification::fake();
        Sanctum::actingAs($this->manager);

        $this->putJson("/api/workspaces/{$this->ws->id}/staff/{$this->unassigned->id}")->assertOk();
        $this->putJson("/api/workspaces/{$this->ws->id}/staff/{$this->unassigned->id}")->assertOk();
        $this->deleteJson("/api/workspaces/{$this->ws->id}/staff/{$this->unassigned->id}")->assertOk();

        Notification::assertSentToTimes($this->unassigned, StaffAssignedNotification::class, 1);
    }

    public function test_the_assigned_notification_says_which_client(): void
    {
        Sanctum::actingAs($this->manager);
        $this->putJson("/api/workspaces/{$this->ws->id}/staff/{$this->unassigned->id}")->assertOk();

        $n = $this->unassigned->notifications()->firstOrFail();
        $this->assertSame('staff_assigned', $n->data['type']);
        $this->assertSame($this->ws->id, $n->data['workspace_id']);
        $this->assertStringContainsString($this->client->company_name, $n->data['message']);
    }

    // ── reading them ──

    public function test_staff_read_their_notifications_for_assigned_clients_only(): void
    {
        $other = Client::factory()->create(['manager_id' => $this->manager->id]);
        $otherWs = Workspace::factory()->create(['client_id' => $other->id, 'manager_id' => $this->manager->id, 'status' => 'active']);

        Sanctum::actingAs($this->client);
        $this->postJson("/api/workspaces/{$this->ws->id}/chat", ['message' => 'about the assigned client'])->assertCreated();

        // A chat notification about a client they are not on (e.g. they were
        // removed) and a payment one — neither may be visible.
        $this->staff->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'x', 'data' => ['type' => 'chat', 'workspace_id' => $otherWs->id, 'client_id' => $other->id, 'message' => 'other client']]);
        $this->staff->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'x', 'data' => ['type' => 'payment_created', 'workspace_id' => $this->ws->id, 'client_id' => $this->client->id, 'message' => 'a payment']]);
        $this->staff->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'x', 'data' => ['type' => 'contract_sent', 'workspace_id' => $this->ws->id, 'client_id' => $this->client->id, 'message' => 'a contract']]);

        Sanctum::actingAs($this->staff);
        $response = $this->getJson('/api/notifications')->assertOk();
        $messages = collect($response->json('notifications'))->pluck('data.message')->all();

        $this->assertContains('رسالة جديدة من ' . $this->client->name, $messages);
        $this->assertNotContains('other client', $messages);
        $this->assertNotContains('a payment', $messages);
        $this->assertNotContains('a contract', $messages);
        $this->assertSame(1, $response->json('unread_count'));
    }

    public function test_unassigning_hides_the_old_notifications_too(): void
    {
        Sanctum::actingAs($this->client);
        $this->postJson("/api/workspaces/{$this->ws->id}/chat", ['message' => 'hello'])->assertCreated();

        Sanctum::actingAs($this->staff);
        $this->assertSame(1, $this->getJson('/api/notifications')->json('unread_count'));

        $this->ws->staff()->detach($this->staff->id);
        $this->assertSame(0, $this->getJson('/api/notifications')->json('unread_count'));
    }

    public function test_mark_all_read_and_opening_the_chat_clear_the_staff_notifications(): void
    {
        Sanctum::actingAs($this->client);
        $this->postJson("/api/workspaces/{$this->ws->id}/chat", ['message' => 'hello'])->assertCreated();

        Sanctum::actingAs($this->staff);
        $this->postJson("/api/workspaces/{$this->ws->id}/chat/mark-read")->assertOk();
        $this->assertSame(0, $this->staff->unreadNotifications()->count());

        Sanctum::actingAs($this->client);
        $this->postJson("/api/workspaces/{$this->ws->id}/chat", ['message' => 'again'])->assertCreated();

        Sanctum::actingAs($this->staff);
        $this->postJson('/api/notifications/read-all')->assertOk();
        $this->assertSame(0, $this->staff->unreadNotifications()->count());
    }

    public function test_the_notification_type_list_for_staff_is_closed(): void
    {
        foreach (['chat', 'approval_responded', 'meeting_reminder', 'staff_assigned'] as $type) {
            $this->assertTrue($this->staff->canSeeNotificationType($type), $type);
        }
        foreach (['payment_created', 'payment_reviewed', 'contract_sent', 'contract_client_approved', 'birthday_reminder', 'workspace_activated', 'approval_requested', 'something_new', null] as $type) {
            $this->assertFalse($this->staff->canSeeNotificationType($type), (string) $type);
        }
        // Managers are unaffected.
        $this->assertTrue($this->manager->canSeeNotificationType('payment_created'));
    }

    // ── audit ──

    public function test_what_staff_do_is_in_the_audit_log_with_their_name_and_visible_to_the_clients_manager(): void
    {
        Mail::fake();
        Sanctum::actingAs($this->staff);
        $this->postJson("/api/workspaces/{$this->ws->id}/approvals", ['title' => 'Logo'])->assertCreated();

        $row = AuditLog::where('action', 'approval.created')->firstOrFail();
        $this->assertSame($this->staff->id, (int) $row->user_id);
        $this->assertSame($this->client->id, (int) $row->client_id);

        Sanctum::actingAs($this->manager);
        $logs = collect($this->getJson('/api/audit-logs')->assertOk()->json('logs.data'));
        $mine = $logs->firstWhere('action', 'approval.created');
        $this->assertNotNull($mine);
        $this->assertSame('Huda', $mine['user']['name']);
        $this->assertSame('staff_member', $mine['user']['role']);
    }

    public function test_assignment_changes_show_in_the_clients_managers_audit_log(): void
    {
        Sanctum::actingAs($this->manager);
        $this->putJson("/api/workspaces/{$this->ws->id}/staff/{$this->unassigned->id}")->assertOk();
        $this->deleteJson("/api/workspaces/{$this->ws->id}/staff/{$this->unassigned->id}")->assertOk();

        $actions = collect($this->getJson('/api/audit-logs')->json('logs.data'))->pluck('action')->all();
        $this->assertContains('staff.assigned', $actions);
        $this->assertContains('staff.unassigned', $actions);
    }
}
