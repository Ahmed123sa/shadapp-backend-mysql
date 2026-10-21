<?php

namespace Tests\Feature;

use App\Events\MeetingCreated;
use App\Models\Client;
use App\Models\Meeting;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\ChatMessageSentNotification;
use App\Notifications\MeetingReminderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** meetings:send-alerts — "starts in 5 minutes" and "has started", each once. */
class SendMeetingAlertsTest extends TestCase
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
        $this->workspace = Workspace::factory()->create(['client_id' => $this->client->id, 'manager_id' => $this->manager->id]);
    }

    private function meetingAt($when, string $status = 'scheduled'): Meeting
    {
        return Meeting::create([
            'workspace_id' => $this->workspace->id,
            'title' => 'Kickoff',
            'scheduled_at' => $when,
            'status' => $status,
            'created_by' => $this->manager->id,
        ]);
    }

    private function kindsSentTo($notifiable): array
    {
        return Notification::sent($notifiable, MeetingReminderNotification::class)
            ->map(fn ($n) => $n->toDatabase($notifiable)['kind'] ?? null)
            ->all();
    }

    public function test_soon_alert_goes_out_once_to_manager_and_client(): void
    {
        $meeting = $this->meetingAt(now()->addMinutes(4));
        Notification::fake();

        $this->artisan('meetings:send-alerts');
        $this->artisan('meetings:send-alerts');

        $this->assertSame(['soon'], $this->kindsSentTo($this->manager));
        $this->assertSame(['soon'], $this->kindsSentTo($this->client));
        $this->assertNotNull($meeting->fresh()->soon_notified_at);
    }

    public function test_nothing_is_sent_while_the_meeting_is_more_than_five_minutes_away(): void
    {
        $this->meetingAt(now()->addMinutes(20));
        Notification::fake();

        $this->artisan('meetings:send-alerts');

        Notification::assertNothingSent();
    }

    public function test_started_alert_goes_out_once_when_the_time_comes(): void
    {
        $meeting = $this->meetingAt(now()->subMinute());
        Notification::fake();

        $this->artisan('meetings:send-alerts');
        $this->artisan('meetings:send-alerts');

        // Only "started": a meeting already under way doesn't also get "in 5 minutes".
        $this->assertSame(['started'], $this->kindsSentTo($this->manager));
        $this->assertSame(['started'], $this->kindsSentTo($this->client));
        $this->assertNotNull($meeting->fresh()->start_notified_at);
    }

    public function test_long_past_meetings_are_not_announced(): void
    {
        $this->meetingAt(now()->subMinutes(30));
        Notification::fake();

        $this->artisan('meetings:send-alerts');

        Notification::assertNothingSent();
    }

    public function test_cancelled_meetings_get_no_alerts(): void
    {
        $this->meetingAt(now()->addMinutes(3), 'cancelled');
        $this->meetingAt(now()->subMinute(), 'cancelled');
        Notification::fake();

        $this->artisan('meetings:send-alerts');

        Notification::assertNothingSent();
    }

    public function test_rescheduling_a_meeting_sends_its_alerts_again_for_the_new_time(): void
    {
        $meeting = $this->meetingAt(now()->addMinutes(3));
        Notification::fake();
        $this->artisan('meetings:send-alerts');
        $this->assertNotNull($meeting->fresh()->soon_notified_at);

        $meeting->fresh()->update(['scheduled_at' => now()->addMinutes(4)]);
        $this->assertNull($meeting->fresh()->soon_notified_at);

        $this->artisan('meetings:send-alerts');
        $this->assertSame(['soon', 'soon'], $this->kindsSentTo($this->client));
    }

    public function test_alert_flags_are_not_part_of_the_api_shape(): void
    {
        $meeting = $this->meetingAt(now()->addMinutes(3));

        $this->assertArrayNotHasKey('soon_notified_at', $meeting->toArray());
        $this->assertArrayNotHasKey('start_notified_at', $meeting->toArray());
    }

    public function test_creating_a_meeting_pushes_its_chat_card_to_the_client(): void
    {
        $meeting = $this->meetingAt(now()->addDay());
        Notification::fake();

        MeetingCreated::dispatch($meeting);

        Notification::assertSentTo($this->client, ChatMessageSentNotification::class);
    }
}
