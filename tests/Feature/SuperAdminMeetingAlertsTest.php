<?php

namespace Tests\Feature;

use App\Events\MeetingCreated;
use App\Models\Client;
use App\Models\Meeting;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\MeetingReminderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The super admin hears about every meeting other people run: once when it is
 * made, once 15 minutes before it starts, and when it is cancelled. Whoever
 * made or cancelled it is not told about their own action.
 */
class SuperAdminMeetingAlertsTest extends TestCase
{
    use RefreshDatabase;

    private User $super;
    private User $manager;
    private Client $client;
    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->super = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $this->manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER, 'name' => 'Mona Manager']);
        $this->client = Client::factory()->create(['manager_id' => $this->manager->id, 'company_name' => 'curve']);
        $this->workspace = Workspace::factory()->create(['client_id' => $this->client->id, 'manager_id' => $this->manager->id]);
    }

    private function meetingAt($when, ?User $creator = null, string $status = 'scheduled'): Meeting
    {
        return Meeting::create([
            'workspace_id' => $this->workspace->id,
            'title' => 'Kickoff',
            'scheduled_at' => $when,
            'status' => $status,
            'created_by' => ($creator ?? $this->manager)->id,
        ]);
    }

    /** The kinds of meeting notification the super admin received, in order. */
    private function kinds(?User $who = null): array
    {
        $who ??= $this->super;

        return Notification::sent($who, MeetingReminderNotification::class)
            ->map(fn ($n) => $n->toDatabase($who)['kind'] ?? null)
            ->values()
            ->all();
    }

    public function test_a_new_meeting_notifies_the_super_admin_with_the_client_and_who_made_it(): void
    {
        Notification::fake();
        $meeting = $this->meetingAt(now()->addDay());

        MeetingCreated::dispatch($meeting);

        $this->assertSame(['created'], $this->kinds());
        $data = Notification::sent($this->super, MeetingReminderNotification::class)->first()->toDatabase($this->super);
        $this->assertSame('meeting_reminder', $data['type']);
        $this->assertStringContainsString('curve', $data['message']);
        $this->assertStringContainsString('Mona Manager', $data['message']);
        $this->assertStringContainsString('New meeting: Kickoff with curve', $data['message_en']);
        $this->assertStringContainsString('by Mona Manager', $data['message_en']);
        $this->assertEquals($this->client->id, $data['client_id']);
    }

    public function test_the_super_admin_is_not_told_about_a_meeting_they_made_themselves(): void
    {
        Notification::fake();
        $meeting = $this->meetingAt(now()->addDay(), $this->super);

        MeetingCreated::dispatch($meeting);
        $this->artisan('meetings:send-alerts');

        $this->assertSame([], $this->kinds());
    }

    public function test_creating_through_the_api_notifies_the_super_admin(): void
    {
        Notification::fake();
        Sanctum::actingAs($this->manager);

        $this->postJson("/api/workspaces/{$this->workspace->id}/meetings", [
            'title' => 'From the app',
            'scheduled_at' => now()->addDay()->toIso8601String(),
        ])->assertCreated();

        $this->assertSame(['created'], $this->kinds());
    }

    public function test_the_reminder_goes_out_15_minutes_ahead_and_only_once(): void
    {
        Notification::fake();
        $meeting = $this->meetingAt(now()->addMinutes(14));

        $this->artisan('meetings:send-alerts');
        $this->artisan('meetings:send-alerts');

        $this->assertSame(['in15'], $this->kinds());
        $this->assertNotNull($meeting->fresh()->sa_reminded_at);
    }

    public function test_nothing_is_sent_while_the_meeting_is_more_than_15_minutes_away(): void
    {
        Notification::fake();
        $this->meetingAt(now()->addMinutes(20));

        $this->artisan('meetings:send-alerts');

        $this->assertSame([], $this->kinds());
    }

    public function test_a_meeting_made_less_than_15_minutes_ahead_gets_the_creation_notice_only(): void
    {
        Notification::fake();
        $meeting = $this->meetingAt(now()->addMinutes(10));

        MeetingCreated::dispatch($meeting);
        $this->artisan('meetings:send-alerts');

        $this->assertSame(['created'], $this->kinds());
    }

    public function test_the_super_admin_who_made_it_gets_no_reminder_either(): void
    {
        Notification::fake();
        $this->meetingAt(now()->addMinutes(10), $this->super);

        $this->artisan('meetings:send-alerts');

        $this->assertSame([], $this->kinds());
    }

    public function test_moving_the_meeting_sends_the_reminder_again_for_the_new_time(): void
    {
        Notification::fake();
        $meeting = $this->meetingAt(now()->addMinutes(14));
        $this->artisan('meetings:send-alerts');
        $this->assertNotNull($meeting->fresh()->sa_reminded_at);

        $meeting->fresh()->update(['scheduled_at' => now()->addMinutes(13)]);
        $this->assertNull($meeting->fresh()->sa_reminded_at);

        $this->artisan('meetings:send-alerts');
        $this->assertSame(['in15', 'in15'], $this->kinds());
    }

    public function test_a_cancelled_meeting_gets_no_reminder(): void
    {
        Notification::fake();
        $this->meetingAt(now()->addMinutes(10), null, 'cancelled');

        $this->artisan('meetings:send-alerts');

        $this->assertSame([], $this->kinds());
    }

    public function test_cancelling_notifies_the_super_admin_with_who_cancelled(): void
    {
        Notification::fake();
        $meeting = $this->meetingAt(now()->addDay());
        Sanctum::actingAs($this->manager);

        $this->patchJson("/api/meetings/{$meeting->id}/cancel")->assertOk();

        $this->assertSame(['cancelled'], $this->kinds());
        $data = Notification::sent($this->super, MeetingReminderNotification::class)->first()->toDatabase($this->super);
        $this->assertStringContainsString('Meeting cancelled: Kickoff with curve', $data['message_en']);
        $this->assertStringContainsString('by Mona Manager', $data['message_en']);
    }

    public function test_cancelling_through_an_update_notifies_too(): void
    {
        Notification::fake();
        $meeting = $this->meetingAt(now()->addDay());
        Sanctum::actingAs($this->manager);

        $this->putJson("/api/workspaces/{$this->workspace->id}/meetings/{$meeting->id}", ['status' => 'cancelled'])->assertOk();

        $this->assertSame(['cancelled'], $this->kinds());
    }

    public function test_the_super_admin_cancelling_is_not_told(): void
    {
        Notification::fake();
        $meeting = $this->meetingAt(now()->addDay());
        Sanctum::actingAs($this->super);

        $this->patchJson("/api/meetings/{$meeting->id}/cancel")->assertOk();

        $this->assertSame([], $this->kinds());
    }

    public function test_the_push_carries_the_same_line_in_both_languages(): void
    {
        $meeting = $this->meetingAt(now()->addDay());
        $n = new MeetingReminderNotification($meeting, 'created', 'Mona Manager');

        $fcm = $n->toFcm($this->super);

        $this->assertStringContainsString('curve', $fcm['body']);
        $this->assertStringContainsString('New meeting: Kickoff with curve', $fcm['body_en']);
        $this->assertSame('meeting', $fcm['data']['type']);
    }

    public function test_the_existing_alerts_are_worded_as_before(): void
    {
        $meeting = $this->meetingAt(now()->addDay());

        $soon = (new MeetingReminderNotification($meeting, 'soon'))->toDatabase($this->manager);
        $this->assertSame('الاجتماع هيبدأ كمان 5 دقايق: Kickoff', $soon['message']);
        $this->assertSame('The meeting starts in 5 minutes: Kickoff', $soon['message_en']);

        $plain = (new MeetingReminderNotification($meeting))->toDatabase($this->manager);
        $this->assertSame('تذكير باجتماع: Kickoff', $plain['message']);
    }

    public function test_the_manager_and_the_client_still_hear_what_they_heard_before(): void
    {
        Notification::fake();
        $meeting = $this->meetingAt(now()->addMinutes(4));

        $this->artisan('meetings:send-alerts');

        $this->assertSame(['soon'], Notification::sent($this->manager, MeetingReminderNotification::class)
            ->map(fn ($n) => $n->toDatabase($this->manager)['kind'])->values()->all());
        $this->assertSame(['soon'], Notification::sent($this->client, MeetingReminderNotification::class)
            ->map(fn ($n) => $n->toDatabase($this->client)['kind'])->values()->all());
        // And the super admin, who is 4 minutes from the meeting, gets the 15-minute reminder.
        $this->assertSame(['in15'], $this->kinds());
    }
}
