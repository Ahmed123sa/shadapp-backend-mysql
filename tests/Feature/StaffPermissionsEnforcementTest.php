<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\Client;
use App\Models\Meeting;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\ApprovalRespondedNotification;
use App\Notifications\ChatMessageSentNotification;
use App\Notifications\MeetingReminderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * PLAN_2_MANAGER_TEAMS.md ف٢ — the six per-client permissions are enforced on
 * the routes (on top of the allow-list) and on the notifications.
 */
class StaffPermissionsEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private User $manager;
    private User $staff;
    private Client $client;
    private Workspace $ws;
    private Workspace $otherWs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $this->manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER, 'super_admin_id' => $this->superAdmin->id, 'is_active' => true]);
        $this->staff = User::factory()->create(['name' => 'Huda', 'role' => User::ROLE_STAFF_MEMBER, 'is_active' => true]);
        $this->client = Client::factory()->create(['manager_id' => $this->manager->id]);
        $this->ws = Workspace::factory()->create(['client_id' => $this->client->id, 'manager_id' => $this->manager->id, 'status' => 'active']);
        $c2 = Client::factory()->create(['manager_id' => $this->manager->id]);
        $this->otherWs = Workspace::factory()->create(['client_id' => $c2->id, 'manager_id' => $this->manager->id, 'status' => 'active']);
    }

    /** Assign with every permission on except $off (and what depends on it). */
    private function assignWithout(?string $off, ?Workspace $ws = null): void
    {
        $map = User::staffPermissionMap($off ? [$off => false] : null);
        ($ws ?? $this->ws)->staff()->syncWithoutDetaching([
            $this->staff->id => ['assigned_by' => $this->superAdmin->id, 'permissions' => User::staffPermissionsForStorage($map) === null ? null : json_encode($map)],
        ]);
    }

    private function meeting(): Meeting
    {
        return Meeting::create([
            'workspace_id' => $this->ws->id, 'title' => 'Kick-off', 'scheduled_at' => now()->addDay(), 'duration_minutes' => 30,
            'status' => 'scheduled', 'created_by' => $this->manager->id, 'zoom_meeting_id' => '123456789',
            'link' => 'https://zoom.us/j/123456789', 'passcode' => 'secret123',
        ]);
    }

    /** key => [requests that need it as [method, url-builder-name, body, ok-status]] */
    public static function keys(): array
    {
        return [
            'view chat' => ['can_view_chat'],
            'send chat' => ['can_send_chat'],
            'view approvals' => ['can_view_approvals'],
            'request approvals' => ['can_request_approvals'],
            'view meetings' => ['can_view_meetings'],
            'join meetings' => ['can_join_meetings'],
        ];
    }

    private function hit(string $key, Workspace $ws, ?Meeting $meeting = null, ?ChatMessage $own = null)
    {
        return match ($key) {
            'can_view_chat' => $this->getJson("/api/workspaces/{$ws->id}/chat"),
            'can_send_chat' => $this->postJson("/api/workspaces/{$ws->id}/chat", ['message' => 'hi']),
            'can_view_approvals' => $this->getJson("/api/workspaces/{$ws->id}/approvals"),
            'can_request_approvals' => $this->postJson("/api/workspaces/{$ws->id}/approvals", ['title' => 'T']),
            'can_view_meetings' => $this->getJson("/api/workspaces/{$ws->id}/meetings"),
            'can_join_meetings' => $this->postJson("/api/meetings/{$meeting->id}/enter"),
        };
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('keys')]
    public function test_each_permission_opens_and_closes_its_own_route(string $key): void
    {
        Cache::flush();
        config(['services.zoom.account_id' => 'a', 'services.zoom.client_id' => 'b', 'services.zoom.client_secret' => 'c']);
        Http::fake();
        $meeting = $this->meeting();
        Sanctum::actingAs($this->staff);

        $this->assignWithout($key);
        $this->hit($key, $this->ws, $meeting)->assertForbidden();

        $this->assignWithout(null);
        $this->ws->staff()->updateExistingPivot($this->staff->id, ['permissions' => null]);
        $this->assertContains($this->hit($key, $this->ws, $meeting)->status(), [200, 201], "$key allowed by default");
    }

    public function test_closing_one_permission_leaves_the_others_open(): void
    {
        $this->assignWithout('can_send_chat');
        Sanctum::actingAs($this->staff);

        $this->getJson("/api/workspaces/{$this->ws->id}/chat")->assertOk();
        $this->postJson("/api/workspaces/{$this->ws->id}/chat/mark-read")->assertOk();
        $this->getJson("/api/workspaces/{$this->ws->id}/approvals")->assertOk();
        $this->postJson("/api/workspaces/{$this->ws->id}/chat", ['message' => 'x'])->assertForbidden();
        $this->assertSame(0, ChatMessage::where('sender_id', $this->staff->id)->count());
    }

    public function test_mark_read_needs_view_chat_and_editing_a_message_needs_send_chat(): void
    {
        $this->assignWithout(null);
        Sanctum::actingAs($this->staff);
        $id = $this->postJson("/api/workspaces/{$this->ws->id}/chat", ['message' => 'mine'])->assertCreated()->json('message.id');

        $this->assignWithout('can_send_chat');
        $this->putJson("/api/chat/{$id}", ['message' => 'edited'])->assertForbidden();

        $this->assignWithout('can_view_chat');
        $this->postJson("/api/workspaces/{$this->ws->id}/chat/mark-read")->assertForbidden();
        // Chat view off takes send off too.
        $this->postJson("/api/workspaces/{$this->ws->id}/chat", ['message' => 'x'])->assertForbidden();
    }

    public function test_permissions_are_per_client(): void
    {
        $this->assignWithout('can_view_chat', $this->ws);
        $this->assignWithout(null, $this->otherWs);
        Sanctum::actingAs($this->staff);

        $this->getJson("/api/workspaces/{$this->ws->id}/chat")->assertForbidden();
        $this->getJson("/api/workspaces/{$this->otherWs->id}/chat")->assertOk();
    }

    public function test_opening_the_workspace_always_works_and_reports_the_permissions(): void
    {
        $this->assignWithout('can_join_meetings');
        Sanctum::actingAs($this->staff);

        $res = $this->getJson("/api/workspaces/{$this->ws->id}")->assertOk();
        $res->assertJsonPath('workspace.my_staff_permissions.can_join_meetings', false);
        $res->assertJsonPath('workspace.my_staff_permissions.can_view_meetings', true);
        $this->getJson("/api/clients/{$this->client->id}")->assertOk();
    }

    public function test_an_unassigned_workspace_is_still_refused_by_the_policies(): void
    {
        $this->assignWithout(null, $this->ws);
        Sanctum::actingAs($this->staff);

        $this->getJson("/api/workspaces/{$this->otherWs->id}/chat")->assertForbidden();
        $this->getJson("/api/workspaces/{$this->otherWs->id}")->assertForbidden();
    }

    public function test_a_legacy_assignment_with_null_permissions_keeps_working(): void
    {
        \DB::table('workspace_staff')->insert(['workspace_id' => $this->ws->id, 'user_id' => $this->staff->id, 'assigned_by' => $this->superAdmin->id, 'created_at' => now(), 'updated_at' => now()]);
        Sanctum::actingAs($this->staff);

        $this->getJson("/api/workspaces/{$this->ws->id}/chat")->assertOk();
        $this->postJson("/api/workspaces/{$this->ws->id}/chat", ['message' => 'hi'])->assertCreated();
        $this->getJson("/api/workspaces/{$this->ws->id}/approvals")->assertOk();
    }

    // ── notifications ──

    public function test_chat_notifications_follow_view_chat(): void
    {
        $this->assignWithout('can_view_chat');
        Notification::fake();
        Sanctum::actingAs($this->client);
        $this->postJson("/api/workspaces/{$this->ws->id}/chat", ['message' => 'hello'])->assertCreated();
        Notification::assertNotSentTo($this->staff, ChatMessageSentNotification::class);

        $this->assignWithout(null);
        $this->ws->staff()->updateExistingPivot($this->staff->id, ['permissions' => null]);
        $this->postJson("/api/workspaces/{$this->ws->id}/chat", ['message' => 'hello again'])->assertCreated();
        Notification::assertSentTo($this->staff, ChatMessageSentNotification::class);
    }

    public function test_approval_answers_follow_view_approvals(): void
    {
        Mail::fake();
        $this->assignWithout('can_view_approvals');
        Sanctum::actingAs($this->manager);
        $this->postJson("/api/workspaces/{$this->ws->id}/approvals", ['title' => 'Budget'])->assertCreated();
        $messageId = ChatMessage::where('workspace_id', $this->ws->id)->where('requires_action', true)->value('id');

        Notification::fake();
        Sanctum::actingAs($this->client);
        $this->postJson("/api/chat/{$messageId}/respond", ['action' => 'edit_requested'])->assertOk();

        Notification::assertSentTo($this->manager, ApprovalRespondedNotification::class);
        Notification::assertNotSentTo($this->staff, ApprovalRespondedNotification::class);
    }

    public function test_meeting_reminders_follow_view_meetings(): void
    {
        $this->assignWithout('can_view_meetings');
        Notification::fake();
        Meeting::create(['workspace_id' => $this->ws->id, 'title' => 'Kick-off', 'scheduled_at' => now()->addMinutes(20), 'duration_minutes' => 30, 'status' => 'scheduled', 'created_by' => $this->manager->id, 'link' => 'https://zoom.us/j/1']);

        $this->artisan('meetings:send-reminders')->assertSuccessful();

        Notification::assertSentTo($this->manager, MeetingReminderNotification::class);
        Notification::assertNotSentTo($this->staff, MeetingReminderNotification::class);
    }

    // ── the stored notifications follow the permissions as they are now ──

    public function test_stored_notifications_follow_the_current_view_permissions(): void
    {
        $this->assignWithout(null);
        \App\Support\StaffNotifier::wasAssigned($this->ws, $this->staff);
        Sanctum::actingAs($this->client);
        $this->postJson("/api/workspaces/{$this->ws->id}/chat", ['message' => 'hello'])->assertCreated();

        Sanctum::actingAs($this->staff);
        $types = fn () => collect($this->getJson('/api/notifications')->json('notifications'))->pluck('data.type')->sort()->values()->all();
        $this->assertSame(['chat', 'staff_assigned'], $types());
        $this->assertSame(2, $this->getJson('/api/notifications')->json('unread_count'));

        // Closing "view chat" now hides the chat one, not the assignment one.
        $this->assignWithout('can_view_chat');
        $this->assertSame(['staff_assigned'], $types());
        $this->assertSame(1, $this->getJson('/api/notifications')->json('unread_count'));
    }

    public function test_stored_notifications_are_filtered_per_client(): void
    {
        $this->assignWithout('can_view_chat');          // closed on this client…
        $this->assignWithout(null, $this->otherWs);     // …open on the other one
        Sanctum::actingAs($this->client);
        $this->postJson("/api/workspaces/{$this->ws->id}/chat", ['message' => 'one'])->assertCreated();
        $otherClient = $this->otherWs->client;
        Sanctum::actingAs($otherClient);
        $this->postJson("/api/workspaces/{$this->otherWs->id}/chat", ['message' => 'two'])->assertCreated();

        Sanctum::actingAs($this->staff);
        $rows = $this->getJson('/api/notifications')->json('notifications');
        $this->assertCount(1, $rows);
        $this->assertSame($this->otherWs->id, (int) $rows[0]['data']['workspace_id']);
    }
}
