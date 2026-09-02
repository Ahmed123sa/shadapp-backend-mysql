<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\Workspace;
use App\Models\Client;
use App\Models\User;
use App\Services\ZoomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZoomWebhookTest extends TestCase
{
    use RefreshDatabase;

    private Meeting $meeting;

    private const SECRET = 'secret-token';

    protected function setUp(): void
    {
        parent::setUp();

        $superAdmin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER, 'super_admin_id' => $superAdmin->id]);
        $client = Client::factory()->create(['manager_id' => $manager->id, 'company_name' => 'Zoom Co']);
        $workspace = Workspace::factory()->create(['client_id' => $client->id, 'manager_id' => $manager->id]);

        $this->meeting = Meeting::create([
            'workspace_id' => $workspace->id,
            'title' => 'Test Meeting',
            'scheduled_at' => now()->addDay(),
            'duration_minutes' => 30,
            'status' => 'scheduled',
            'zoom_meeting_id' => '9876543210',
            'created_by' => $manager->id,
        ]);

        config(['services.zoom.webhook_secret' => self::SECRET]);
    }

    /**
     * Builds the headers Zoom actually sends: the signature covers
     * "v0:{timestamp}:{body}" and is prefixed with "v0=".
     *
     * These tests used to pass by setting the secret to '' and posting with no
     * signature at all, which only worked because verification failed open —
     * so they locked the vulnerability in instead of catching it. Signing for
     * real here means the wire format is now under test.
     */
    private function zoomHeaders(array $payload, ?int $timestamp = null): array
    {
        $timestamp ??= time();
        $body = json_encode($payload);
        $signature = 'v0=' . hash_hmac('sha256', "v0:{$timestamp}:{$body}", self::SECRET);

        return [
            'X-Zm-Request-Timestamp' => (string) $timestamp,
            'X-Zm-Signature' => $signature,
        ];
    }

    public function test_endpoint_url_validation_returns_encrypted_token(): void
    {
        // Zoom sends the validation challenge unsigned, so this path runs
        // before signature verification and needs no headers.
        $response = $this->postJson('/api/webhooks/zoom', [
            'event' => 'endpoint.url_validation',
            'endpoint_url_validation' => ['plainToken' => 'abc'],
        ]);

        $response->assertOk();
        $this->assertEquals('abc', $response->json('plainToken'));
        $this->assertEquals(
            hash_hmac('sha256', 'abc', self::SECRET),
            $response->json('encryptedToken')
        );
    }

    public function test_recording_completed_saves_download_url_from_recording_files(): void
    {
        $payload = [
            'event' => 'recording.completed',
            'payload' => [
                'object' => [
                    'id' => '9876543210',
                    'recording_files' => [
                        [
                            'file_type' => 'MP4',
                            'download_url' => 'https://zoom.us/rec/play/download-url.mp4',
                        ],
                    ],
                ],
            ],
        ];

        $this->postJson('/api/webhooks/zoom', $payload, $this->zoomHeaders($payload))->assertOk();

        $this->assertEquals(
            'https://zoom.us/rec/play/download-url.mp4',
            $this->meeting->fresh()->recording_url
        );
    }

    public function test_recording_completed_ignores_files_without_download_url(): void
    {
        $payload = [
            'event' => 'recording.completed',
            'payload' => [
                'object' => [
                    'id' => '9876543210',
                    'recording_files' => [
                        ['file_type' => 'TIMELINE', 'download_url' => null],
                    ],
                ],
            ],
        ];

        $this->postJson('/api/webhooks/zoom', $payload, $this->zoomHeaders($payload))->assertOk();

        $this->assertNull($this->meeting->fresh()->recording_url);
    }

    public function test_meeting_ended_auto_completes_scheduled_meeting(): void
    {
        $payload = [
            'event' => 'meeting.ended',
            'payload' => ['object' => ['id' => '9876543210']],
        ];

        $this->postJson('/api/webhooks/zoom', $payload, $this->zoomHeaders($payload))->assertOk();

        $fresh = $this->meeting->fresh();
        $this->assertEquals('completed', $fresh->status);
        $this->assertNotNull($fresh->ended_at);
        $this->assertNotNull($fresh->zoom_ended_at);
    }

    public function test_invalid_signature_rejected_when_secret_configured(): void
    {
        $payload = [
            'event' => 'meeting.ended',
            'payload' => ['object' => ['id' => '9876543210']],
        ];

        $response = $this->postJson('/api/webhooks/zoom', $payload, [
            'X-Zm-Request-Timestamp' => (string) time(),
            'X-Zm-Signature' => 'v0=wrong-signature',
        ]);

        $response->assertStatus(401);
        $this->assertEquals('scheduled', $this->meeting->fresh()->status);
    }

    /**
     * The test that was missing. Only the rejection path was covered, so
     * nothing ever exercised the wire format Zoom actually sends — which is
     * how the "hash the body alone" bug survived: it rejects everything, and
     * a test that only checks rejection can't tell that apart from working.
     */
    public function test_correctly_signed_webhook_is_accepted(): void
    {
        $payload = [
            'event' => 'meeting.ended',
            'payload' => ['object' => ['id' => '9876543210']],
        ];

        $this->postJson('/api/webhooks/zoom', $payload, $this->zoomHeaders($payload))
            ->assertOk()
            ->assertJson(['status' => 'ok']);

        $this->assertEquals('completed', $this->meeting->fresh()->status);
    }

    public function test_webhook_rejected_when_secret_is_not_configured(): void
    {
        // Fail closed: a deployment that forgets ZOOM_WEBHOOK_SECRET_TOKEN
        // must reject webhooks, not accept every unauthenticated POST.
        config(['services.zoom.webhook_secret' => '']);

        $payload = [
            'event' => 'meeting.ended',
            'payload' => ['object' => ['id' => '9876543210']],
        ];

        $this->postJson('/api/webhooks/zoom', $payload)->assertStatus(401);
        $this->assertEquals('scheduled', $this->meeting->fresh()->status);
    }

    public function test_unsigned_webhook_is_rejected(): void
    {
        $payload = [
            'event' => 'meeting.ended',
            'payload' => ['object' => ['id' => '9876543210']],
        ];

        $this->postJson('/api/webhooks/zoom', $payload)->assertStatus(401);
        $this->assertEquals('scheduled', $this->meeting->fresh()->status);
    }

    public function test_replayed_webhook_with_stale_timestamp_is_rejected(): void
    {
        $payload = [
            'event' => 'meeting.ended',
            'payload' => ['object' => ['id' => '9876543210']],
        ];

        // Correctly signed, but captured an hour ago.
        $headers = $this->zoomHeaders($payload, time() - 3600);

        $this->postJson('/api/webhooks/zoom', $payload, $headers)->assertStatus(401);
        $this->assertEquals('scheduled', $this->meeting->fresh()->status);
    }

    private function participantPayload(string $event, array $participant): array
    {
        return [
            'event' => $event,
            'payload' => ['object' => ['id' => '9876543210', 'participant' => $participant]],
        ];
    }

    private function postParticipantEvent(string $event, array $participant): void
    {
        $payload = $this->participantPayload($event, $participant);
        $this->postJson('/api/webhooks/zoom', $payload, $this->zoomHeaders($payload))->assertOk();
    }

    public function test_rejoining_does_not_add_a_second_attendee_row(): void
    {
        // Zoom re-sends participant_joined on every reconnect, which is
        // routine on a flaky connection — one person must stay one row.
        $participant = ['participant_uuid' => 'uuid-1', 'user_name' => 'Sara', 'email' => 'sara@example.com'];

        $this->postParticipantEvent('meeting.participant_joined', $participant);
        $this->postParticipantEvent('meeting.participant_joined', $participant);

        $this->assertCount(1, $this->meeting->fresh()->zoom_attendees);
    }

    public function test_rejoining_after_leaving_opens_a_new_attendance_row(): void
    {
        $participant = ['participant_uuid' => 'uuid-1', 'user_name' => 'Sara', 'email' => 'sara@example.com'];

        $this->postParticipantEvent('meeting.participant_joined', $participant);
        $this->postParticipantEvent('meeting.participant_left', $participant);
        $this->postParticipantEvent('meeting.participant_joined', $participant);

        $attendees = $this->meeting->fresh()->zoom_attendees;
        $this->assertCount(2, $attendees);
        $this->assertArrayHasKey('left_at', $attendees[0]);
        $this->assertArrayNotHasKey('left_at', $attendees[1]);
    }

    public function test_leaving_closes_the_right_row_when_participants_have_no_email(): void
    {
        // Zoom omits `email` for participants who join without signing in.
        // Matching on email alone made `null === null` close whichever
        // anonymous attendee happened to be first in the list.
        $first = ['participant_uuid' => 'uuid-1', 'user_name' => 'Guest One'];
        $second = ['participant_uuid' => 'uuid-2', 'user_name' => 'Guest Two'];

        $this->postParticipantEvent('meeting.participant_joined', $first);
        $this->postParticipantEvent('meeting.participant_joined', $second);
        $this->postParticipantEvent('meeting.participant_left', $second);

        $attendees = $this->meeting->fresh()->zoom_attendees;
        $this->assertCount(2, $attendees);
        $this->assertArrayNotHasKey('left_at', $attendees[0], 'Guest One should still be marked present');
        $this->assertArrayHasKey('left_at', $attendees[1], 'Guest Two is the one who left');
    }

    public function test_leave_event_with_no_identifier_closes_nobody(): void
    {
        $this->postParticipantEvent('meeting.participant_joined', [
            'participant_uuid' => 'uuid-1',
            'user_name' => 'Sara',
        ]);

        $this->postParticipantEvent('meeting.participant_left', ['user_name' => 'Unknown']);

        $attendees = $this->meeting->fresh()->zoom_attendees;
        $this->assertCount(1, $attendees);
        $this->assertArrayNotHasKey('left_at', $attendees[0]);
    }

    public function test_update_meeting_returns_empty_array_on_204_response(): void
    {
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
            'api.zoom.us/v2/meetings/*' => Http::response('', 204),
        ]);

        $result = app(ZoomService::class)->updateMeeting('1111111111', ['topic' => 'Updated']);

        $this->assertIsArray($result);
        $this->assertSame([], $result);
    }

    public function test_update_meeting_returns_body_on_json_response(): void
    {
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
            'api.zoom.us/v2/meetings/2222222222' => Http::response(['id' => 2222222222, 'topic' => 'Updated']),
        ]);

        $result = app(ZoomService::class)->updateMeeting('2222222222', ['topic' => 'Updated']);

        $this->assertEquals(2222222222, $result['id']);
        $this->assertEquals('Updated', $result['topic']);
    }

    public function test_delete_meeting_returns_true_on_success(): void
    {
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
            'api.zoom.us/v2/meetings/3333333333' => Http::response('', 204),
        ]);

        $result = app(ZoomService::class)->deleteMeeting('3333333333');

        $this->assertTrue($result);
    }

    public function test_delete_meeting_throws_on_failure(): void
    {
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
            'api.zoom.us/v2/meetings/4444444444' => Http::response(['code' => 3001, 'message' => 'Meeting not found'], 404),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Zoom meeting deletion failed');
        app(ZoomService::class)->deleteMeeting('4444444444');
    }
}
