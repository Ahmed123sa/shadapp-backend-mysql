<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\Client;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\ApprovalRequestedNotification;
use App\Notifications\ApprovalRespondedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * 23 Sept 2026 — an approval request used to notify the workspace manager
 * and every super admin, never the client who actually has to respond, and
 * its payload had no workspace_id, so tapping it went nowhere. Now the
 * client is notified (plus the manager when a super admin raised it), super
 * admins aren't, and both approval notifications carry workspace/client ids.
 */
class ApprovalRequestNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $manager;
    private Client $client;
    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $this->manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $this->client = Client::factory()->create(['manager_id' => $this->manager->id]);
        $this->workspace = Workspace::factory()->create([
            'client_id' => $this->client->id,
            'manager_id' => $this->manager->id,
        ]);
        Notification::fake();
    }

    private function requestApprovalAs(User $user): void
    {
        $this->actingAs($user)
            ->postJson("/api/workspaces/{$this->workspace->id}/approvals", ['title' => 'Logo sign-off'])
            ->assertStatus(201);
    }

    public function test_a_managers_request_notifies_the_client_only(): void
    {
        $this->requestApprovalAs($this->manager);

        Notification::assertSentTo($this->client, ApprovalRequestedNotification::class);
        Notification::assertNotSentTo($this->admin, ApprovalRequestedNotification::class);
        Notification::assertNotSentTo($this->manager, ApprovalRequestedNotification::class);
    }

    public function test_a_super_admins_request_notifies_the_client_and_the_manager(): void
    {
        $this->requestApprovalAs($this->admin);

        Notification::assertSentTo($this->client, ApprovalRequestedNotification::class);
        Notification::assertSentTo($this->manager, ApprovalRequestedNotification::class);
        Notification::assertNotSentTo($this->admin, ApprovalRequestedNotification::class);
    }

    public function test_the_requested_notification_carries_the_workspace_and_client(): void
    {
        $approval = Approval::factory()->create(['workspace_id' => $this->workspace->id, 'requested_by' => $this->manager->id]);
        $notification = new ApprovalRequestedNotification($approval);

        $data = $notification->toDatabase($this->client);
        $this->assertEquals($this->workspace->id, $data['workspace_id']);
        $this->assertEquals($this->client->id, $data['client_id']);

        $push = $notification->toFcm($this->client)['data'];
        $this->assertSame((string) $this->workspace->id, $push['workspace_id']);
        $this->assertSame((string) $this->client->id, $push['client_id']);
    }

    public function test_the_responded_notification_carries_the_workspace_and_client(): void
    {
        $approval = Approval::factory()->create(['workspace_id' => $this->workspace->id, 'requested_by' => $this->manager->id, 'status' => 'approved']);
        $notification = new ApprovalRespondedNotification($approval);

        $data = $notification->toDatabase($this->manager);
        $this->assertEquals($this->workspace->id, $data['workspace_id']);
        $this->assertEquals($this->client->id, $data['client_id']);

        $push = $notification->toFcm($this->manager)['data'];
        $this->assertSame((string) $this->workspace->id, $push['workspace_id']);
        $this->assertSame((string) $this->client->id, $push['client_id']);
    }
}
