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
    }

    public function test_endpoint_url_validation_returns_encrypted_token(): void
    {
        config(['services.zoom.webhook_secret' => 'secret-token']);

        $response = $this->postJson('/api/webhooks/zoom', [
            'event' => 'endpoint.url_validation',
            'endpoint_url_validation' => ['plainToken' => 'abc'],
        ]);

        $response->assertOk();
        $this->assertEquals('abc', $response->json('plainToken'));
        $this->assertEquals(
            hash_hmac('sha256', 'abc', 'secret-token'),
            $response->json('encryptedToken')
        );
    }

    public function test_recording_completed_saves_download_url_from_recording_files(): void
    {
        config(['services.zoom.webhook_secret' => '']);

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

        $this->postJson('/api/webhooks/zoom', $payload)->assertOk();

        $this->assertEquals(
            'https://zoom.us/rec/play/download-url.mp4',
            $this->meeting->fresh()->recording_url
        );
    }

    public function test_recording_completed_ignores_files_without_download_url(): void
    {
        config(['services.zoom.webhook_secret' => '']);

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

        $this->postJson('/api/webhooks/zoom', $payload)->assertOk();

        $this->assertNull($this->meeting->fresh()->recording_url);
    }

    public function test_meeting_ended_auto_completes_scheduled_meeting(): void
    {
        config(['services.zoom.webhook_secret' => '']);

        $payload = [
            'event' => 'meeting.ended',
            'payload' => ['object' => ['id' => '9876543210']],
        ];

        $this->postJson('/api/webhooks/zoom', $payload)->assertOk();

        $fresh = $this->meeting->fresh();
        $this->assertEquals('completed', $fresh->status);
        $this->assertNotNull($fresh->ended_at);
        $this->assertNotNull($fresh->zoom_ended_at);
    }

    public function test_invalid_signature_rejected_when_secret_configured(): void
    {
        config(['services.zoom.webhook_secret' => 'secret-token']);

        $payload = [
            'event' => 'meeting.ended',
            'payload' => ['object' => ['id' => '9876543210']],
        ];

        $response = $this->postJson('/api/webhooks/zoom', $payload, [
            'X-Zm-Signature' => 'wrong-signature',
        ]);

        $response->assertStatus(401);
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
