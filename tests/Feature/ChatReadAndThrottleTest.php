<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\Client;
use App\Models\MobileNotificationToken;
use App\Models\SubUser;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\ChatMessageSentNotification;
use App\Services\FirebaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * plans/notifications-badges-toasts-plan.md ن7 + ن16 (§3 س5 + س1).
 *
 * ن7 — ChatController::markAsRead used to compare `sender_type !=
 * get_class($user)`. A sub-user (class SubUser) opening the chat therefore
 * marked the PRIMARY CLIENT's own messages read too (Client::class !=
 * SubUser::class), and DashboardController::clientCounts' chat count only
 * excluded Client::class, so a sub-user's own sent message counted as
 * "unread" against itself. The workaround (no per-user read table) treats
 * Client and SubUser as one "client side".
 *
 * ن16 — every chat message triggered its own push forever, and chat
 * notifications were never marked read by anything. The decided fix (§3 س1):
 * markAsRead also marks this workspace's chat notifications read, and a new
 * chat message skips the FCM push (but still writes the database row and
 * broadcasts) if the recipient already has an unread chat notification for
 * this workspace from the last 5 minutes.
 */
class ChatReadAndThrottleTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private Client $client;
    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $this->client = Client::factory()->create(['manager_id' => $this->manager->id]);
        $this->workspace = Workspace::factory()->create([
            'client_id' => $this->client->id,
            'manager_id' => $this->manager->id,
        ]);
    }

    private function message(string $senderType, int $senderId): ChatMessage
    {
        return ChatMessage::create([
            'workspace_id' => $this->workspace->id,
            'sender_type' => $senderType,
            'sender_id' => $senderId,
            'message' => 'hi',
            'type' => 'text',
        ]);
    }

    // ---------------------------------------------------------------
    // ن7 — chat message read marking
    // ---------------------------------------------------------------

    public function test_a_sub_user_reading_the_chat_does_not_mark_the_primary_clients_own_messages_read(): void
    {
        $subUser = SubUser::factory()->create(['client_id' => $this->client->id]);
        $fromManager = $this->message(User::class, $this->manager->id);
        $fromClient = $this->message(Client::class, $this->client->id);

        $this->actingAs($subUser, 'sub_user')
            ->postJson("/api/workspaces/{$this->workspace->id}/chat/mark-read")
            ->assertOk();

        $this->assertNotNull($fromManager->fresh()->read_at);
        $this->assertNull($fromClient->fresh()->read_at, 'a sub-user reading the chat must not touch the primary client\'s own messages');
    }

    public function test_a_manager_reading_the_chat_marks_both_client_and_sub_user_messages_read(): void
    {
        $subUser = SubUser::factory()->create(['client_id' => $this->client->id]);
        $fromClient = $this->message(Client::class, $this->client->id);
        $fromSubUser = $this->message(SubUser::class, $subUser->id);

        $this->actingAs($this->manager)
            ->postJson("/api/workspaces/{$this->workspace->id}/chat/mark-read")
            ->assertOk();

        $this->assertNotNull($fromClient->fresh()->read_at);
        $this->assertNotNull($fromSubUser->fresh()->read_at);
    }

    // ---------------------------------------------------------------
    // ن7 — badge-counts self-counting
    // ---------------------------------------------------------------

    public function test_a_sub_users_own_sent_message_does_not_count_as_unread_chat_on_its_own_badge(): void
    {
        $subUser = SubUser::factory()->create([
            'client_id' => $this->client->id,
            'permissions' => ['can_chat' => true],
        ]);
        $this->message(SubUser::class, $subUser->id);

        $response = $this->actingAs($subUser, 'sub_user')->getJson('/api/badge-counts')->assertOk();

        $this->assertSame(0, $response->json('chat'));
    }

    public function test_a_sub_users_own_sent_message_does_not_count_as_unread_chat_on_the_clients_badge(): void
    {
        $subUser = SubUser::factory()->create(['client_id' => $this->client->id]);
        $this->message(SubUser::class, $subUser->id);

        $response = $this->actingAs($this->client, 'client')->getJson('/api/badge-counts')->assertOk();

        $this->assertSame(0, $response->json('chat'));
    }

    public function test_a_message_from_staff_does_count_as_unread_chat_on_the_clients_badge(): void
    {
        $this->message(User::class, $this->manager->id);

        $response = $this->actingAs($this->client, 'client')->getJson('/api/badge-counts')->assertOk();

        $this->assertSame(1, $response->json('chat'));
    }

    // ---------------------------------------------------------------
    // ن16 #1 — markAsRead also clears the chat notification(s)
    // ---------------------------------------------------------------

    public function test_reading_the_chat_marks_the_clients_chat_notification_read(): void
    {
        $this->actingAs($this->manager)
            ->postJson("/api/workspaces/{$this->workspace->id}/chat", ['message' => 'hello'])
            ->assertCreated();

        $this->assertSame(1, $this->client->notifications()->where('data->type', 'chat')->whereNull('read_at')->count());

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->client, 'client')
            ->postJson("/api/workspaces/{$this->workspace->id}/chat/mark-read")
            ->assertOk();

        $this->assertSame(0, $this->client->notifications()->where('data->type', 'chat')->whereNull('read_at')->count());
    }

    // Chat notifications for a client-sent/sub-user-sent message are always
    // created against the assigned manager (a User) — never against a
    // sub-user directly — so a sub-user reading the chat clears the parent
    // client's own notification the same way a direct client read would.
    public function test_a_sub_user_reading_the_chat_clears_the_parent_clients_chat_notification(): void
    {
        $this->actingAs($this->manager)
            ->postJson("/api/workspaces/{$this->workspace->id}/chat", ['message' => 'hello'])
            ->assertCreated();

        $subUser = SubUser::factory()->create([
            'client_id' => $this->client->id,
            'permissions' => ['can_chat' => true],
        ]);

        $this->app['auth']->forgetGuards();
        $this->actingAs($subUser, 'sub_user')
            ->postJson("/api/workspaces/{$this->workspace->id}/chat/mark-read")
            ->assertOk();

        $this->assertSame(0, $this->client->notifications()->where('data->type', 'chat')->whereNull('read_at')->count());
    }

    // ---------------------------------------------------------------
    // ن16 #2 — push throttle
    // ---------------------------------------------------------------

    // Takes $sent by reference rather than returning a new array — a mocked
    // closure only ever mutates the array it captured at mock-setup time, so
    // returning a fresh array from this helper would silently stay empty no
    // matter how many times sendMessage() was actually called.
    private function mockPushCount(array &$sent): void
    {
        $this->mock(FirebaseService::class, function ($mock) use (&$sent) {
            $mock->shouldReceive('sendMessage')->andReturnUsing(function () use (&$sent) {
                $sent[] = true;
                return true;
            });
        });
    }

    public function test_a_second_chat_message_within_5_minutes_does_not_push_again(): void
    {
        MobileNotificationToken::create([
            'token' => 'manager-token',
            'tokenable_id' => $this->manager->id,
            'tokenable_type' => User::class,
            'device_type' => 'android',
        ]);
        $sent = [];
        $this->mockPushCount($sent);

        $this->actingAs($this->client, 'client')
            ->postJson("/api/workspaces/{$this->workspace->id}/chat", ['message' => 'one'])
            ->assertCreated();
        $this->assertCount(1, $sent);

        $this->actingAs($this->client, 'client')
            ->postJson("/api/workspaces/{$this->workspace->id}/chat", ['message' => 'two'])
            ->assertCreated();
        $this->assertCount(1, $sent, 'a second message within the 5-minute window must not push again');

        // The database row and the broadcast must still happen every message
        // — only the push is throttled.
        $this->assertSame(2, $this->manager->notifications()->where('data->type', 'chat')->count());
    }

    public function test_push_resumes_once_the_manager_reads_the_chat(): void
    {
        MobileNotificationToken::create([
            'token' => 'manager-token',
            'tokenable_id' => $this->manager->id,
            'tokenable_type' => User::class,
            'device_type' => 'android',
        ]);
        $sent = [];
        $this->mockPushCount($sent);

        $this->actingAs($this->client, 'client')
            ->postJson("/api/workspaces/{$this->workspace->id}/chat", ['message' => 'one'])
            ->assertCreated();
        $this->actingAs($this->client, 'client')
            ->postJson("/api/workspaces/{$this->workspace->id}/chat", ['message' => 'two'])
            ->assertCreated();
        $this->assertCount(1, $sent);

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->manager)
            ->postJson("/api/workspaces/{$this->workspace->id}/chat/mark-read")
            ->assertOk();

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->client, 'client')
            ->postJson("/api/workspaces/{$this->workspace->id}/chat", ['message' => 'three'])
            ->assertCreated();

        $this->assertCount(2, $sent, 'reading the chat clears the throttle for the next message');
    }

    public function test_push_resumes_after_5_minutes_even_if_still_unread(): void
    {
        MobileNotificationToken::create([
            'token' => 'manager-token',
            'tokenable_id' => $this->manager->id,
            'tokenable_type' => User::class,
            'device_type' => 'android',
        ]);
        $sent = [];
        $this->mockPushCount($sent);

        $this->actingAs($this->client, 'client')
            ->postJson("/api/workspaces/{$this->workspace->id}/chat", ['message' => 'one'])
            ->assertCreated();
        $this->assertCount(1, $sent);

        $this->travel(6)->minutes();

        $this->actingAs($this->client, 'client')
            ->postJson("/api/workspaces/{$this->workspace->id}/chat", ['message' => 'two'])
            ->assertCreated();

        $this->assertCount(2, $sent, 'the 5-minute throttle window must expire even without the manager reading');
    }

    // The notification's own via() is what actually drops the FCM channel;
    // this pins that behavior directly rather than only through the push
    // side-effect above.
    public function test_the_throttled_notification_instance_has_skip_push_set(): void
    {
        Notification::fake();

        $notified = new ChatMessageSentNotification($this->message(User::class, $this->manager->id), skipPush: true);
        $notNotified = new ChatMessageSentNotification($this->message(User::class, $this->manager->id), skipPush: false);

        $this->assertNotContains(\App\Notifications\FcmChannel::class, $notified->via($this->client));
        $this->assertContains(\App\Notifications\FcmChannel::class, $notNotified->via($this->client));
    }
}
