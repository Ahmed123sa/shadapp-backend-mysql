<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\Client;
use App\Models\Meeting;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\ChatMessageSentNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * 23 Sept 2026 — creating a meeting posts a 'meeting' chat message whose
 * metadata is a snapshot of the meeting (CreateMeetingChatMessage). Its
 * snapshot was never updated afterwards, so a completed, cancelled,
 * retitled or rescheduled meeting still showed its original values in the
 * chat. MeetingObserver now syncs the snapshot on every relevant change,
 * from any path, and a reschedule also posts a new card and notifies the
 * client.
 */
class MeetingChatStatusSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $client = Client::factory()->create(['manager_id' => $this->manager->id]);
        $this->workspace = Workspace::factory()->create([
            'client_id' => $client->id,
            'manager_id' => $this->manager->id,
        ]);
    }

    private function createMeeting(string $title): Meeting
    {
        $id = $this->actingAs($this->manager)
            ->postJson("/api/workspaces/{$this->workspace->id}/meetings", [
                'title' => $title,
                'scheduled_at' => now()->addDay()->toIso8601String(),
            ])
            ->assertStatus(201)
            ->json('meeting.id');

        return Meeting::findOrFail($id);
    }

    private function chatStatusFor(Meeting $meeting): ?string
    {
        $message = ChatMessage::where('workspace_id', $this->workspace->id)
            ->where('type', 'meeting')
            ->get()
            ->first(fn (ChatMessage $m) => (int) ($m->metadata['meeting_id'] ?? 0) === $meeting->id);

        return $message?->metadata['status'] ?? null;
    }

    public function test_the_chat_card_starts_out_scheduled(): void
    {
        $meeting = $this->createMeeting('Kickoff');

        $this->assertSame('scheduled', $this->chatStatusFor($meeting));
    }

    public function test_completing_a_meeting_updates_its_chat_card(): void
    {
        $meeting = $this->createMeeting('Kickoff');

        $this->actingAs($this->manager)->patchJson("/api/meetings/{$meeting->id}/complete")->assertOk();

        $this->assertSame('completed', $this->chatStatusFor($meeting));
    }

    public function test_cancelling_a_meeting_updates_its_chat_card(): void
    {
        $meeting = $this->createMeeting('Kickoff');

        $this->actingAs($this->manager)->patchJson("/api/meetings/{$meeting->id}/cancel")->assertOk();

        $this->assertSame('cancelled', $this->chatStatusFor($meeting));
    }

    public function test_the_scheduled_auto_complete_command_updates_the_chat_card_too(): void
    {
        $meeting = $this->createMeeting('Kickoff');
        // Move it into the past without firing model events, as if time had
        // simply passed.
        $meeting->forceFill(['scheduled_at' => now()->subHours(2), 'duration_minutes' => 30])->saveQuietly();

        $this->artisan('meetings:update-statuses')->assertExitCode(0);

        $this->assertSame('completed', $this->chatStatusFor($meeting));
    }

    public function test_another_meetings_chat_card_is_left_alone(): void
    {
        $done = $this->createMeeting('Kickoff');
        $other = $this->createMeeting('Follow-up');

        $this->actingAs($this->manager)->patchJson("/api/meetings/{$done->id}/complete")->assertOk();

        $this->assertSame('completed', $this->chatStatusFor($done));
        $this->assertSame('scheduled', $this->chatStatusFor($other));
    }

    public function test_the_rest_of_the_snapshot_is_preserved(): void
    {
        $meeting = $this->createMeeting('Kickoff');

        $this->actingAs($this->manager)->patchJson("/api/meetings/{$meeting->id}/complete")->assertOk();

        $message = ChatMessage::where('workspace_id', $this->workspace->id)->where('type', 'meeting')->firstOrFail();
        $this->assertSame('Kickoff', $message->metadata['title']);
        $this->assertSame($meeting->id, (int) $message->metadata['meeting_id']);
    }

    // --- 23 Sept 2026: title/time edits and reschedule notice -------------

    private function meetingCards(Meeting $meeting)
    {
        return ChatMessage::where('workspace_id', $this->workspace->id)
            ->where('type', 'meeting')
            ->orderBy('id')
            ->get()
            ->filter(fn (ChatMessage $m) => (int) ($m->metadata['meeting_id'] ?? 0) === $meeting->id)
            ->values();
    }

    private function edit(Meeting $meeting, array $fields): void
    {
        $this->actingAs($this->manager)
            ->putJson("/api/workspaces/{$this->workspace->id}/meetings/{$meeting->id}", $fields)
            ->assertOk();
    }

    public function test_renaming_a_meeting_updates_its_chat_card_without_posting_a_new_one(): void
    {
        $meeting = $this->createMeeting('Kickoff');

        $this->edit($meeting, ['title' => 'Kickoff v2']);

        $cards = $this->meetingCards($meeting);
        $this->assertCount(1, $cards);
        $this->assertSame('Kickoff v2', $cards[0]->metadata['title']);
        $this->assertSame('📹 Kickoff v2', $cards[0]->message);
    }

    public function test_rescheduling_updates_the_old_card_and_posts_a_new_one(): void
    {
        $meeting = $this->createMeeting('Kickoff');
        // Faked only after creation, so creation's own notifications don't
        // count towards the assertions below.
        Notification::fake();
        $newTime = now()->addDays(3)->startOfHour();

        $this->edit($meeting, ['scheduled_at' => $newTime->toIso8601String()]);

        $expected = $meeting->fresh()->scheduled_at->toIso8601String();
        $cards = $this->meetingCards($meeting);
        $this->assertCount(2, $cards);

        // The original card now shows the new time too, in the same format
        // CreateMeetingChatMessage uses.
        $this->assertSame($expected, $cards[0]->metadata['scheduled_at']);
        $this->assertArrayNotHasKey('rescheduled', $cards[0]->metadata);

        // The new card, at the bottom of the chat, flagged as a reschedule.
        $this->assertSame($expected, $cards[1]->metadata['scheduled_at']);
        $this->assertTrue($cards[1]->metadata['rescheduled']);
        $this->assertSame('📅 تم تغيير ميعاد اجتماع: Kickoff', $cards[1]->message);
        $this->assertSame($this->manager->id, (int) $cards[1]->sender_id);
        $this->assertSame(User::class, $cards[1]->sender_type);

        Notification::assertSentTo($this->workspace->client, ChatMessageSentNotification::class);
    }

    public function test_editing_only_the_notes_changes_nothing_in_the_chat(): void
    {
        $meeting = $this->createMeeting('Kickoff');
        // Faked only after creation, so creation's own notifications don't
        // count towards the assertions below.
        Notification::fake();
        $before = $this->meetingCards($meeting)->first()->metadata;

        $this->edit($meeting, ['notes' => 'Bring the deck']);

        $cards = $this->meetingCards($meeting);
        $this->assertCount(1, $cards);
        $this->assertSame($before, $cards[0]->metadata);
        Notification::assertNothingSentTo($this->workspace->client);
    }

    public function test_moving_a_cancelled_meetings_time_posts_no_reschedule_card(): void
    {
        $meeting = $this->createMeeting('Kickoff');
        $this->actingAs($this->manager)->patchJson("/api/meetings/{$meeting->id}/cancel")->assertOk();

        $this->edit($meeting, ['scheduled_at' => now()->addDays(5)->toIso8601String()]);

        $this->assertCount(1, $this->meetingCards($meeting));
    }

    public function test_a_later_status_change_also_reaches_the_reschedule_card(): void
    {
        $meeting = $this->createMeeting('Kickoff');
        $this->edit($meeting, ['scheduled_at' => now()->addDays(3)->toIso8601String()]);

        $this->actingAs($this->manager)->patchJson("/api/meetings/{$meeting->id}/complete")->assertOk();

        $cards = $this->meetingCards($meeting);
        $this->assertCount(2, $cards);
        $this->assertSame('completed', $cards[0]->metadata['status']);
        $this->assertSame('completed', $cards[1]->metadata['status']);
        $this->assertTrue($cards[1]->metadata['rescheduled']);
    }

    // --- 23 Sept 2026: edit/delete routes and existing observer behaviour ---

    public function test_editing_a_meeting_through_the_workspace_route_succeeds(): void
    {
        // PUT /workspaces/{workspace}/meetings/{meeting} used to 500 with a
        // TypeError (the controller only declared $meeting).
        $meeting = $this->createMeeting('Kickoff');

        $this->actingAs($this->manager)
            ->putJson("/api/workspaces/{$this->workspace->id}/meetings/{$meeting->id}", ['title' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('meeting.title', 'Renamed');
    }

    public function test_deleting_a_meeting_through_the_workspace_route_removes_its_chat_card(): void
    {
        $meeting = $this->createMeeting('Kickoff');

        $this->actingAs($this->manager)
            ->deleteJson("/api/workspaces/{$this->workspace->id}/meetings/{$meeting->id}")
            ->assertOk();

        $this->assertNull(Meeting::find($meeting->id));
        $this->assertCount(0, $this->meetingCards($meeting));
    }

    public function test_a_meeting_cannot_be_edited_through_another_workspaces_route(): void
    {
        $meeting = $this->createMeeting('Kickoff');
        $otherClient = Client::factory()->create(['manager_id' => $this->manager->id]);
        $otherWorkspace = Workspace::factory()->create([
            'client_id' => $otherClient->id,
            'manager_id' => $this->manager->id,
        ]);

        $this->actingAs($this->manager)
            ->putJson("/api/workspaces/{$otherWorkspace->id}/meetings/{$meeting->id}", ['title' => 'Renamed'])
            ->assertNotFound();
    }

    public function test_cancelling_still_hides_the_join_link_on_the_chat_card(): void
    {
        $meeting = $this->createMeeting('Kickoff');
        $meeting->forceFill(['link' => 'https://zoom.example/j/1', 'passcode' => '123'])->save();
        $this->assertSame('https://zoom.example/j/1', $this->meetingCards($meeting)[0]->metadata['link']);

        $this->actingAs($this->manager)->patchJson("/api/meetings/{$meeting->id}/cancel")->assertOk();

        $card = $this->meetingCards($meeting)[0];
        $this->assertSame('cancelled', $card->metadata['status']);
        $this->assertNull($card->metadata['link']);
        $this->assertNull($card->metadata['passcode']);
    }
}
