<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Meeting;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\MeetingReminderNotification;
use App\Support\DisplayTime;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 23 Sept 2026 — mobile sends scheduled_at with the phone's UTC offset
 * ("2026-10-01T18:00:00+03:00"); the web dashboard sends UTC ("...Z"). Both
 * must end up as the same instant in the database. Server-rendered text
 * (emails, push bodies, PDFs) shows times in Egypt time
 * (config('app.display_timezone')).
 */
class MeetingTimezoneTest extends TestCase
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

    private function create(string $scheduledAt): Meeting
    {
        $id = $this->actingAs($this->manager)
            ->postJson("/api/workspaces/{$this->workspace->id}/meetings", [
                'title' => 'Kickoff',
                'scheduled_at' => $scheduledAt,
            ])
            ->assertStatus(201)
            ->json('meeting.id');

        return Meeting::findOrFail($id);
    }

    // Mobile's format: 18:00 in a UTC+3 timezone is 15:00 UTC.
    public function test_a_time_sent_with_an_offset_is_stored_as_the_same_instant(): void
    {
        $meeting = $this->create('2026-10-01T18:00:00.000+03:00');

        $this->assertSame('2026-10-01 15:00', $meeting->scheduled_at->utc()->format('Y-m-d H:i'));
    }

    // The web dashboard's format (toISOString) — already UTC.
    public function test_a_utc_time_is_stored_unchanged(): void
    {
        $meeting = $this->create('2026-10-01T15:00:00.000Z');

        $this->assertSame('2026-10-01 15:00', $meeting->scheduled_at->utc()->format('Y-m-d H:i'));
    }

    // Both apps must get the same instant back for the same meeting time.
    public function test_mobile_and_web_formats_for_the_same_moment_agree(): void
    {
        $fromMobile = $this->create('2026-10-01T18:00:00.000+03:00');
        $fromWeb = $this->create('2026-10-01T15:00:00.000Z');

        $this->assertTrue($fromMobile->scheduled_at->equalTo($fromWeb->scheduled_at));
    }

    public function test_editing_with_an_offset_also_stores_the_right_instant(): void
    {
        $meeting = $this->create('2026-10-01T15:00:00.000Z');

        $this->actingAs($this->manager)
            ->putJson("/api/workspaces/{$this->workspace->id}/meetings/{$meeting->id}", [
                'scheduled_at' => '2026-10-02T20:30:00.000+03:00',
            ])
            ->assertOk();

        $this->assertSame('2026-10-02 17:30', $meeting->fresh()->scheduled_at->utc()->format('Y-m-d H:i'));
    }

    public function test_the_api_returns_the_utc_instant(): void
    {
        $this->create('2026-10-01T18:00:00.000+03:00');

        $this->actingAs($this->manager)
            ->getJson("/api/workspaces/{$this->workspace->id}/meetings")
            ->assertOk()
            ->assertSee('2026-10-01T15:00:00', false);
    }

    // --- Egypt time for server-rendered text ---------------------------------

    public function test_display_time_uses_egypt_time_in_winter(): void
    {
        // Egypt is UTC+2 outside summer time.
        $this->assertSame('2026-12-01 17:00', DisplayTime::format(Carbon::parse('2026-12-01 15:00:00', 'UTC')));
    }

    public function test_display_time_follows_egypt_summer_time(): void
    {
        // Egypt summer time (UTC+3) runs until the last Thursday of October.
        $this->assertSame('2026-10-01 18:00', DisplayTime::format(Carbon::parse('2026-10-01 15:00:00', 'UTC')));
    }

    public function test_the_meeting_email_shows_egypt_time(): void
    {
        $meeting = $this->create('2026-12-01T15:00:00.000Z');

        $html = view('emails.meeting-scheduled', ['meeting' => $meeting])->render();

        $this->assertStringContainsString('17:00', $html);
        $this->assertStringNotContainsString('15:00', $html);
    }

    public function test_the_reminder_push_shows_egypt_time(): void
    {
        $meeting = $this->create('2026-12-01T15:00:00.000Z');

        $body = (new MeetingReminderNotification($meeting))->toFcm($this->manager)['body'];

        $this->assertStringContainsString('2026-12-01 17:00', $body);
    }
}
