<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Meeting;
use App\Models\MobileNotificationToken;
use App\Models\Payment;
use App\Models\SubUser;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\ApprovalRequestedNotification;
use App\Notifications\ApprovalRespondedNotification;
use App\Notifications\BirthdayReminderNotification;
use App\Notifications\ContractClientApprovedNotification;
use App\Notifications\ContractCompanyApprovedNotification;
use App\Notifications\ContractCompletedNotification;
use App\Notifications\ContractSentNotification;
use App\Notifications\FcmChannel;
use App\Notifications\MeetingReminderNotification;
use App\Notifications\PaymentCreatedNotification;
use App\Notifications\PaymentReviewedNotification;
use App\Services\FirebaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationSystemTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private Client $client;
    private Workspace $workspace;
    private Contract $contract;
    private Payment $payment;
    private Approval $approval;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $this->client = Client::factory()->create(['manager_id' => $this->manager->id]);

        $this->workspace = Workspace::factory()->create([
            'client_id' => $this->client->id,
            'manager_id' => $this->manager->id,
        ]);

        $this->contract = Contract::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->manager->id,
        ]);

        $this->payment = Payment::factory()->create([
            'workspace_id' => $this->workspace->id,
            'client_id' => $this->client->id,
            'amount' => '5000.00',
            'status' => 'pending',
        ]);

        $this->approval = Approval::factory()->create([
            'workspace_id' => $this->workspace->id,
            'approvable_type' => 'workspace',
            'approvable_id' => $this->workspace->id,
            'requested_by' => $this->manager->id,
            'status' => 'pending',
        ]);
    }

    public function test_each_notification_is_sent_to_correct_notifiable(): void
    {
        Notification::fake();

        $this->manager->notify(new ContractSentNotification($this->contract));
        Notification::assertSentTo($this->manager, ContractSentNotification::class);

        $this->manager->notify(new ContractClientApprovedNotification($this->contract));
        Notification::assertSentTo($this->manager, ContractClientApprovedNotification::class);

        $this->client->notify(new ContractCompanyApprovedNotification($this->contract));
        Notification::assertSentTo($this->client, ContractCompanyApprovedNotification::class);

        $this->manager->notify(new ContractCompletedNotification($this->contract));
        Notification::assertSentTo($this->manager, ContractCompletedNotification::class);

        $this->manager->notify(new PaymentCreatedNotification($this->payment));
        Notification::assertSentTo($this->manager, PaymentCreatedNotification::class);

        $this->client->notify(new PaymentReviewedNotification($this->payment, 'approved'));
        Notification::assertSentTo($this->client, PaymentReviewedNotification::class);

        $this->manager->notify(new ApprovalRequestedNotification($this->approval));
        Notification::assertSentTo($this->manager, ApprovalRequestedNotification::class);

        $this->manager->notify(new ApprovalRespondedNotification($this->approval));
        Notification::assertSentTo($this->manager, ApprovalRespondedNotification::class);
    }

    public function test_database_notifications_are_stored_and_readable(): void
    {
        $this->manager->notify(new ContractSentNotification($this->contract));
        $this->manager->notify(new ContractClientApprovedNotification($this->contract));

        $this->assertCount(2, $this->manager->notifications);
    }

    public function test_notification_api_read_and_delete(): void
    {
        $this->manager->notify(new ContractSentNotification($this->contract));
        $this->manager->notify(new ContractClientApprovedNotification($this->contract));

        $response = $this->actingAs($this->manager, 'sanctum')->getJson('/api/notifications');
        $response->assertStatus(200);
        $response->assertJsonStructure(['notifications', 'unread_count']);
        $this->assertCount(2, $response->json('notifications'));
        $this->assertEquals(2, $response->json('unread_count'));

        $notifId = $response->json('notifications.0.id');

        $response = $this->actingAs($this->manager, 'sanctum')->postJson("/api/notifications/{$notifId}/read");
        $response->assertStatus(200);

        $response = $this->actingAs($this->manager, 'sanctum')->getJson('/api/notifications');
        $this->assertEquals(1, $response->json('unread_count'));

        $response = $this->actingAs($this->manager, 'sanctum')->postJson('/api/notifications/read-all');
        $response->assertStatus(200);

        $response = $this->actingAs($this->manager, 'sanctum')->getJson('/api/notifications');
        $this->assertEquals(0, $response->json('unread_count'));

        $response = $this->actingAs($this->manager, 'sanctum')->deleteJson("/api/notifications/{$notifId}");
        $response->assertStatus(200);

        $response = $this->actingAs($this->manager, 'sanctum')->getJson('/api/notifications');
        $this->assertCount(1, $response->json('notifications'));
    }

    public function test_register_token_works_for_dashboard_user(): void
    {
        $response = $this->actingAs($this->manager, 'sanctum')->postJson('/api/notifications/register-token', [
            'token' => 'dashboard-device',
            'device_type' => 'android',
        ]);
        $response->assertStatus(200);

        $this->assertDatabaseHas('mobile_notification_tokens', [
            'token' => 'dashboard-device',
            'tokenable_id' => $this->manager->id,
            'tokenable_type' => User::class,
        ]);
    }

    public function test_register_token_works_for_client(): void
    {
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->client->createToken('test')->plainTextToken])
            ->postJson('/api/notifications/register-token', [
                'token' => 'client-device',
                'device_type' => 'ios',
            ]);
        $response->assertStatus(200);

        $this->assertDatabaseHas('mobile_notification_tokens', [
            'token' => 'client-device',
            'tokenable_id' => $this->client->id,
            'tokenable_type' => Client::class,
        ]);
    }

    public function test_client_notification_flow(): void
    {
        MobileNotificationToken::create([
            'token' => 'client-notif-token',
            'tokenable_id' => $this->client->id,
            'tokenable_type' => Client::class,
            'device_type' => 'android',
        ]);

        $this->client->notify(new ContractCompanyApprovedNotification($this->contract));

        $token = $this->client->createToken('test')->plainTextToken;
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/notifications');

        $response->assertStatus(200);
        $response->assertJsonStructure(['notifications', 'unread_count']);
        $this->assertGreaterThan(0, $response->json('unread_count'));
    }

    public function test_multi_device_tokens(): void
    {
        MobileNotificationToken::insert([
            ['token' => 'device-1', 'tokenable_id' => $this->manager->id, 'tokenable_type' => User::class, 'device_type' => 'android'],
            ['token' => 'device-2', 'tokenable_id' => $this->manager->id, 'tokenable_type' => User::class, 'device_type' => 'ios'],
            ['token' => 'device-3', 'tokenable_id' => $this->manager->id, 'tokenable_type' => User::class, 'device_type' => 'android'],
        ]);

        $tokens = MobileNotificationToken::where('tokenable_id', $this->manager->id)
            ->where('tokenable_type', User::class)
            ->pluck('token');

        $this->assertCount(3, $tokens);
    }

    public function test_send_fcm_endpoint_requires_auth(): void
    {
        $response = $this->postJson('/api/notifications/send-fcm', [
            'user_id' => $this->manager->id,
            'user_type' => User::class,
            'title' => 'Test',
            'body' => 'Test body',
        ]);
        $response->assertStatus(401);
    }

    public function test_register_token_multi_device(): void
    {
        $this->actingAs($this->manager, 'sanctum')->postJson('/api/notifications/register-token', [
            'token' => 'phone-1',
            'device_type' => 'android',
        ]);
        $this->actingAs($this->manager, 'sanctum')->postJson('/api/notifications/register-token', [
            'token' => 'phone-2',
            'device_type' => 'ios',
        ]);

        $tokens = MobileNotificationToken::where('tokenable_id', $this->manager->id)
            ->where('tokenable_type', User::class)
            ->pluck('token');

        $this->assertCount(2, $tokens);
        $this->assertContains('phone-1', $tokens);
        $this->assertContains('phone-2', $tokens);
    }

    // plans/notifications-badges-toasts-plan.md ن1 — logout never removed the
    // device's token, so the next person to log in on the same phone kept
    // getting the previous account's push notifications.
    public function test_unregister_token_removes_it(): void
    {
        $this->actingAs($this->manager, 'sanctum')->postJson('/api/notifications/register-token', [
            'token' => 'to-be-removed',
            'device_type' => 'android',
        ]);
        $this->assertDatabaseHas('mobile_notification_tokens', ['token' => 'to-be-removed']);

        $response = $this->actingAs($this->manager, 'sanctum')->postJson('/api/notifications/unregister-token', [
            'token' => 'to-be-removed',
        ]);
        $response->assertStatus(200);

        $this->assertDatabaseMissing('mobile_notification_tokens', ['token' => 'to-be-removed']);
    }

    public function test_unregister_token_requires_auth(): void
    {
        $response = $this->postJson('/api/notifications/unregister-token', ['token' => 'anything']);
        $response->assertStatus(401);
    }

    public function test_unregister_token_ignores_a_token_that_does_not_exist(): void
    {
        $response = $this->actingAs($this->manager, 'sanctum')->postJson('/api/notifications/unregister-token', [
            'token' => 'never-registered',
        ]);
        $response->assertStatus(200);
    }

    // plans/notifications-badges-toasts-plan.md ن2 — a sub-user used to see
    // every one of its parent client's notifications regardless of its own
    // permissions, unlike /badge-counts, which already zeroes out counts the
    // sub-user isn't permitted to view.
    public function test_sub_user_only_sees_notifications_for_permissions_it_has(): void
    {
        $subUser = SubUser::factory()->create([
            'client_id' => $this->client->id,
            'permissions' => ['can_view_contracts' => true, 'can_view_payments' => false, 'can_view_approvals' => false],
        ]);

        $this->client->notify(new ContractSentNotification($this->contract));
        $this->client->notify(new PaymentCreatedNotification($this->payment));
        $this->client->notify(new ApprovalRequestedNotification($this->approval));

        $response = $this->actingAs($subUser, 'sub_user')->getJson('/api/notifications');
        $response->assertStatus(200);

        $types = collect($response->json('notifications'))->pluck('data.type');
        $this->assertContains('contract_sent', $types);
        $this->assertNotContains('payment_created', $types);
        $this->assertNotContains('approval_requested', $types);
        $this->assertEquals(1, $response->json('unread_count'));
    }

    // A sub-user with every relevant permission sees the same list a client
    // logged in directly would.
    public function test_sub_user_sees_everything_when_it_has_every_permission(): void
    {
        $subUser = SubUser::factory()->create([
            'client_id' => $this->client->id,
            'permissions' => ['can_view_contracts' => true, 'can_view_payments' => true, 'can_view_approvals' => true],
        ]);

        $this->client->notify(new ContractSentNotification($this->contract));
        $this->client->notify(new PaymentCreatedNotification($this->payment));
        $this->client->notify(new ApprovalRequestedNotification($this->approval));

        $response = $this->actingAs($subUser, 'sub_user')->getJson('/api/notifications');

        $this->assertCount(3, $response->json('notifications'));
    }

    public function test_sub_user_mark_all_as_read_only_marks_notifications_it_can_see(): void
    {
        $subUser = SubUser::factory()->create([
            'client_id' => $this->client->id,
            'permissions' => ['can_view_contracts' => true, 'can_view_payments' => false],
        ]);
        $this->client->notify(new ContractSentNotification($this->contract));
        $this->client->notify(new PaymentCreatedNotification($this->payment));

        $response = $this->actingAs($subUser, 'sub_user')->postJson('/api/notifications/read-all');
        $response->assertStatus(200);

        // A blanket update(['read_at' => now()]) here would have marked both
        // read on behalf of a sub-user who was never shown the payment one.
        $this->assertCount(1, $this->client->fresh()->readNotifications);
        $this->assertCount(1, $this->client->fresh()->unreadNotifications);
    }

    public function test_sub_user_cannot_mark_as_read_a_notification_type_it_lacks_permission_for(): void
    {
        $subUser = SubUser::factory()->create([
            'client_id' => $this->client->id,
            'permissions' => ['can_view_payments' => false],
        ]);
        $this->client->notify(new PaymentCreatedNotification($this->payment));
        $notifId = $this->client->fresh()->notifications->first()->id;

        $response = $this->actingAs($subUser, 'sub_user')->postJson("/api/notifications/{$notifId}/read");
        $response->assertStatus(200);

        $this->assertNull($this->client->fresh()->notifications->first()->read_at);
    }

    // plans/notifications-badges-toasts-plan.md ن3 — a manager's own
    // birthday/meeting reminders had none of workspace_id/contract_id/
    // payment_id/approval_id, so GET /notifications' AM filter dropped them
    // even though the push notification for the same event reached the
    // manager fine.
    public function test_manager_sees_birthday_and_meeting_reminders_via_workspace_id(): void
    {
        $meeting = Meeting::create([
            'workspace_id' => $this->workspace->id,
            'title' => 'Kickoff',
            'scheduled_at' => now()->addMinutes(15),
            'created_by' => $this->manager->id,
        ]);

        $this->manager->notify(new BirthdayReminderNotification($this->client));
        $this->manager->notify(new MeetingReminderNotification($meeting));

        $response = $this->actingAs($this->manager, 'sanctum')->getJson('/api/notifications');

        $types = collect($response->json('notifications'))->pluck('data.type');
        $this->assertContains('birthday_reminder', $types);
        $this->assertContains('meeting_reminder', $types);
    }

    // A birthday reminder stored before workspace_id existed on it is still
    // recovered via the client_id fallback, so already-sent reminders don't
    // just disappear once this ships.
    public function test_manager_sees_a_pre_existing_birthday_reminder_via_client_id_fallback(): void
    {
        $this->manager->notify(new BirthdayReminderNotification($this->client));
        $stored = $this->manager->notifications()->first();
        $data = $stored->data;
        unset($data['workspace_id']);
        $stored->forceFill(['data' => $data])->save();

        $response = $this->actingAs($this->manager, 'sanctum')->getJson('/api/notifications');

        $types = collect($response->json('notifications'))->pluck('data.type');
        $this->assertContains('birthday_reminder', $types);
    }

    public function test_fcm_channel_sends_without_exception(): void
    {
        MobileNotificationToken::create([
            'token' => 'fcm-unit-test-token',
            'tokenable_id' => $this->manager->id,
            'tokenable_type' => User::class,
            'device_type' => 'android',
        ]);

        $channel = new FcmChannel();
        $notification = new ContractSentNotification($this->contract);

        $channel->send($this->manager, $notification);
        $this->assertTrue(true, 'FcmChannel did not throw');
    }

    // plans/notifications-badges-toasts-plan.md ن14/ح2ب — a sub-user got no
    // push at all before this, even for a type it's fully permitted to see.
    // FcmChannel now also sends to a Client-directed notification's eligible
    // sub-users, gated by the same SubUser::canSeeNotificationType() used by
    // GET /notifications.
    public function test_sub_user_receives_push_when_permitted_for_the_notification_type(): void
    {
        $subUser = SubUser::factory()->create([
            'client_id' => $this->client->id,
            'permissions' => ['can_view_contracts' => true],
        ]);
        MobileNotificationToken::create([
            'token' => 'sub-user-allowed-token',
            'tokenable_id' => $subUser->id,
            'tokenable_type' => SubUser::class,
            'device_type' => 'android',
        ]);

        $sentTokens = [];
        $this->mock(FirebaseService::class, function ($mock) use (&$sentTokens) {
            $mock->shouldReceive('sendMessage')
                ->andReturnUsing(function (string $token) use (&$sentTokens) {
                    $sentTokens[] = $token;
                    return true;
                });
        });

        (new FcmChannel())->send($this->client, new ContractSentNotification($this->contract));

        $this->assertContains('sub-user-allowed-token', $sentTokens);
    }

    public function test_sub_user_does_not_receive_push_when_lacking_permission_for_the_notification_type(): void
    {
        $subUser = SubUser::factory()->create([
            'client_id' => $this->client->id,
            'permissions' => ['can_view_contracts' => false],
        ]);
        MobileNotificationToken::create([
            'token' => 'sub-user-denied-token',
            'tokenable_id' => $subUser->id,
            'tokenable_type' => SubUser::class,
            'device_type' => 'android',
        ]);

        $sentTokens = [];
        $this->mock(FirebaseService::class, function ($mock) use (&$sentTokens) {
            $mock->shouldReceive('sendMessage')
                ->andReturnUsing(function (string $token) use (&$sentTokens) {
                    $sentTokens[] = $token;
                    return true;
                });
        });

        (new FcmChannel())->send($this->client, new ContractSentNotification($this->contract));

        $this->assertNotContains('sub-user-denied-token', $sentTokens);
    }

    // A notification sent directly to a User (a manager's own push, e.g.
    // MeetingReminderNotification) must never touch sub-user gating at all —
    // only Client-directed notifications flow through a sub-user's eyes.
    public function test_push_to_a_manager_does_not_attempt_sub_user_gating(): void
    {
        MobileNotificationToken::create([
            'token' => 'manager-token',
            'tokenable_id' => $this->manager->id,
            'tokenable_type' => User::class,
            'device_type' => 'android',
        ]);

        $sentTokens = [];
        $this->mock(FirebaseService::class, function ($mock) use (&$sentTokens) {
            $mock->shouldReceive('sendMessage')
                ->andReturnUsing(function (string $token) use (&$sentTokens) {
                    $sentTokens[] = $token;
                    return true;
                });
        });

        (new FcmChannel())->send($this->manager, new ContractSentNotification($this->contract));

        $this->assertEquals(['manager-token'], $sentTokens);
    }
}
