<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Meeting;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\MeetingReminderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SendMeetingRemindersTest extends TestCase
{
    use RefreshDatabase;

    private function makeMeeting(Workspace $workspace, User $creator): Meeting
    {
        return Meeting::create([
            'workspace_id' => $workspace->id,
            'title' => 'Kickoff',
            'scheduled_at' => now()->addMinutes(10),
            'created_by' => $creator->id,
        ]);
    }

    public function test_notifies_creator_manager_and_client_when_all_three_are_distinct(): void
    {
        Notification::fake();

        $creator = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $client = Client::factory()->create(['manager_id' => $manager->id]);
        $workspace = Workspace::factory()->create(['client_id' => $client->id, 'manager_id' => $manager->id]);
        $meeting = $this->makeMeeting($workspace, $creator);

        $this->artisan('meetings:send-reminders');

        Notification::assertSentTo($creator, MeetingReminderNotification::class);
        Notification::assertSentTo($manager, MeetingReminderNotification::class);
        Notification::assertSentTo($client, MeetingReminderNotification::class);
    }

    // plans/notifications-badges-toasts-plan.md ن3 — the old code guarded the
    // client notification with `$client->id !== ($creator?->id) &&
    // $client->id !== ($manager?->id)`. clients and users are unrelated
    // tables with independent auto-increment sequences, so a client and a
    // user ending up with the same numeric id is a coincidence that
    // eventually happens in any long-running system — and when it did, this
    // comparison silently skipped notifying the client every time that
    // meeting's reminder fired, forever, since created_by/workspace.manager
    // never change. This forces exactly that collision to prove it no longer
    // matters.
    public function test_notifies_the_client_even_when_its_id_numerically_collides_with_the_creator(): void
    {
        Notification::fake();

        $creator = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);

        $client = Client::factory()->make(['manager_id' => $manager->id]);
        $client->id = $creator->id;
        $client->save();

        $workspace = Workspace::factory()->create(['client_id' => $client->id, 'manager_id' => $manager->id]);
        $meeting = $this->makeMeeting($workspace, $creator);

        $this->artisan('meetings:send-reminders');

        Notification::assertSentTo($client, MeetingReminderNotification::class);
    }

    public function test_notifies_the_client_even_when_its_id_numerically_collides_with_the_manager(): void
    {
        Notification::fake();

        $creator = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);

        $client = Client::factory()->make(['manager_id' => $manager->id]);
        $client->id = $manager->id;
        $client->save();

        $workspace = Workspace::factory()->create(['client_id' => $client->id, 'manager_id' => $manager->id]);
        $meeting = $this->makeMeeting($workspace, $creator);

        $this->artisan('meetings:send-reminders');

        Notification::assertSentTo($client, MeetingReminderNotification::class);
    }
}
