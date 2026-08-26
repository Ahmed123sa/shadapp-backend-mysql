<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\Client;
use App\Models\Contract;
use App\Models\MobileNotificationToken;
use App\Models\Payment;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\ApprovalRequestedNotification;
use App\Notifications\ApprovalRespondedNotification;
use App\Notifications\ContractClientApprovedNotification;
use App\Notifications\ContractCompanyApprovedNotification;
use App\Notifications\ContractCompletedNotification;
use App\Notifications\ContractSentNotification;
use App\Notifications\FcmChannel;
use App\Notifications\PaymentCreatedNotification;
use App\Notifications\PaymentReviewedNotification;
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
}
