<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\Client;
use App\Models\Meeting;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 23 Sept 2026 — creating a meeting posts a 'meeting' chat message whose
 * metadata is a snapshot of the meeting (CreateMeetingChatMessage). Its
 * status was never updated afterwards, so a completed or cancelled meeting
 * still looked scheduled in the chat. Meeting::booted() now syncs the
 * status into that message on every status change, from any path.
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
}
