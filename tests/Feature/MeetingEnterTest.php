<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Meeting;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ZoomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MeetingEnterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'services.zoom.account_id' => 'test-account',
            'services.zoom.client_id' => 'test-client',
            'services.zoom.client_secret' => 'test-secret',
        ]);
    }

    private function createMeetingSetup(array $meetingOverrides = []): array
    {
        $superAdmin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $otherManager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $client = Client::factory()->create(['manager_id' => $manager->id]);

        $workspace = Workspace::factory()->create([
            'client_id' => $client->id,
            'manager_id' => $manager->id,
            'status' => 'active',
        ]);

        $meeting = Meeting::create(array_merge([
            'workspace_id' => $workspace->id,
            'title' => 'Initial Consultation',
            'scheduled_at' => now()->addDay(),
            'duration_minutes' => 45,
            'status' => 'scheduled',
            'created_by' => $manager->id,
            'zoom_meeting_id' => '123456789',
            'link' => 'https://zoom.us/j/123456789',
            'passcode' => 'secret123',
        ], $meetingOverrides));

        return compact('superAdmin', 'manager', 'otherManager', 'client', 'workspace', 'meeting');
    }

    private function fakeZoom(string $status = 'waiting', string $startUrl = 'https://zoom.us/s/123456789?zak=token123'): void
    {
        Http::fake([
            'https://zoom.us/oauth/token*' => Http::response([
                'access_token' => 'mock_token',
                'expires_in' => 3600,
            ], 200),
            'https://api.zoom.us/v2/meetings/*' => Http::response([
                'id' => 123456789,
                'status' => $status,
                'start_url' => $startUrl,
                'join_url' => 'https://zoom.us/j/123456789',
            ], 200),
        ]);
    }

    public function test_manager_enters_first_becomes_host(): void
    {
        $setup = $this->createMeetingSetup();
        $this->fakeZoom('waiting', 'https://zoom.us/s/123456789?zak=host_token_1');

        Sanctum::actingAs($setup['manager'], ['*']);
        $response = $this->postJson("/api/meetings/{$setup['meeting']->id}/enter");

        $response->assertOk();
        $response->assertJson([
            'as' => 'host',
            'url' => 'https://zoom.us/s/123456789?zak=host_token_1',
        ]);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $this->assertEquals($setup['manager']->id, Cache::get($setup['meeting']->hostCacheKey()));
    }

    public function test_super_admin_enters_first_becomes_host(): void
    {
        $setup = $this->createMeetingSetup();
        $this->fakeZoom('waiting', 'https://zoom.us/s/123456789?zak=sa_token');

        Sanctum::actingAs($setup['superAdmin'], ['*']);
        $response = $this->postJson("/api/meetings/{$setup['meeting']->id}/enter");

        $response->assertOk();
        $response->assertJson([
            'as' => 'host',
            'url' => 'https://zoom.us/s/123456789?zak=sa_token',
        ]);

        $this->assertEquals($setup['superAdmin']->id, Cache::get($setup['meeting']->hostCacheKey()));
    }

    public function test_manager_started_then_super_admin_gets_participant_without_calling_zoom_api(): void
    {
        $setup = $this->createMeetingSetup();
        $this->fakeZoom('waiting', 'https://zoom.us/s/123456789?zak=mgr_token');

        // 1. Manager starts as host
        Sanctum::actingAs($setup['manager'], ['*']);
        $mgrResponse = $this->postJson("/api/meetings/{$setup['meeting']->id}/enter");
        $mgrResponse->assertJson(['as' => 'host']);

        // 2. Super admin enters second
        Sanctum::actingAs($setup['superAdmin'], ['*']);
        $saResponse = $this->postJson("/api/meetings/{$setup['meeting']->id}/enter");

        $saResponse->assertOk();
        $saResponse->assertJson([
            'as' => 'participant',
            'url' => 'https://zoom.us/j/123456789',
        ]);

        // Zoom getMeeting API was called only once (by manager), not a second time for SA
        Http::assertSentCount(2); // 1 token + 1 getMeeting
    }

    public function test_super_admin_started_then_manager_gets_participant(): void
    {
        $setup = $this->createMeetingSetup();
        $this->fakeZoom('waiting', 'https://zoom.us/s/123456789?zak=sa_token');

        // 1. Super Admin starts as host
        Sanctum::actingAs($setup['superAdmin'], ['*']);
        $this->postJson("/api/meetings/{$setup['meeting']->id}/enter")->assertJson(['as' => 'host']);

        // 2. Manager enters second
        Sanctum::actingAs($setup['manager'], ['*']);
        $mgrResponse = $this->postJson("/api/meetings/{$setup['meeting']->id}/enter");

        $mgrResponse->assertOk();
        $mgrResponse->assertJson([
            'as' => 'participant',
            'url' => 'https://zoom.us/j/123456789',
        ]);
    }

    public function test_host_themselves_rejoining_gets_fresh_start_url(): void
    {
        $setup = $this->createMeetingSetup();

        $callCount = 0;
        Http::fake([
            'https://zoom.us/oauth/token*' => Http::response([
                'access_token' => 'mock_token',
                'expires_in' => 3600,
            ], 200),
            'https://api.zoom.us/v2/meetings/*' => function () use (&$callCount) {
                $callCount++;
                return Http::response([
                    'id' => 123456789,
                    'status' => $callCount === 1 ? 'waiting' : 'started',
                    'start_url' => "https://zoom.us/s/123456789?zak=token_v{$callCount}",
                    'join_url' => 'https://zoom.us/j/123456789',
                ], 200);
            },
        ]);

        Sanctum::actingAs($setup['manager'], ['*']);
        $this->postJson("/api/meetings/{$setup['meeting']->id}/enter")->assertJson(['as' => 'host', 'url' => 'https://zoom.us/s/123456789?zak=token_v1']);

        $rejoinResponse = $this->postJson("/api/meetings/{$setup['meeting']->id}/enter");
        $rejoinResponse->assertOk();
        $rejoinResponse->assertJson([
            'as' => 'host',
            'url' => 'https://zoom.us/s/123456789?zak=token_v2',
        ]);
    }

    public function test_cache_cleared_but_zoom_already_started_gives_participant(): void
    {
        $setup = $this->createMeetingSetup();
        // Zoom says meeting is already started, and cache is empty
        $this->fakeZoom('started', 'https://zoom.us/s/123456789?zak=some_token');

        Sanctum::actingAs($setup['manager'], ['*']);
        $response = $this->postJson("/api/meetings/{$setup['meeting']->id}/enter");

        $response->assertOk();
        $response->assertJson([
            'as' => 'participant',
            'url' => 'https://zoom.us/j/123456789',
        ]);

        // Cache claim was released
        $this->assertNull(Cache::get($setup['meeting']->hostCacheKey()));
    }

    public function test_unauthorized_manager_gets_forbidden(): void
    {
        $setup = $this->createMeetingSetup();
        Sanctum::actingAs($setup['otherManager'], ['*']);

        $response = $this->postJson("/api/meetings/{$setup['meeting']->id}/enter");
        $response->assertForbidden();
    }

    public function test_client_gets_forbidden(): void
    {
        $setup = $this->createMeetingSetup();
        Sanctum::actingAs($setup['client'], ['*']);

        $response = $this->postJson("/api/meetings/{$setup['meeting']->id}/enter");
        $response->assertForbidden();
    }

    public function test_non_scheduled_meeting_returns_unprocessable(): void
    {
        $setup = $this->createMeetingSetup(['status' => 'completed']);
        Sanctum::actingAs($setup['manager'], ['*']);

        $response = $this->postJson("/api/meetings/{$setup['meeting']->id}/enter");
        $response->assertStatus(422);
    }

    public function test_meeting_without_zoom_returns_participant_with_raw_link(): void
    {
        $setup = $this->createMeetingSetup([
            'zoom_meeting_id' => null,
            'link' => 'https://meet.google.com/abc-defg-hij',
        ]);
        Sanctum::actingAs($setup['manager'], ['*']);

        $response = $this->postJson("/api/meetings/{$setup['meeting']->id}/enter");
        $response->assertOk();
        $response->assertJson([
            'as' => 'participant',
            'url' => 'https://meet.google.com/abc-defg-hij',
        ]);
    }

    public function test_zoom_failure_returns_bad_gateway_and_releases_lock(): void
    {
        $setup = $this->createMeetingSetup();

        Http::fake([
            'https://zoom.us/oauth/token*' => Http::response(['access_token' => 'mock_token', 'expires_in' => 3600], 200),
            'https://api.zoom.us/v2/meetings/*' => Http::response(['message' => 'Internal Server Error'], 500),
        ]);

        Sanctum::actingAs($setup['manager'], ['*']);
        $response = $this->postJson("/api/meetings/{$setup['meeting']->id}/enter");

        $response->assertStatus(502);
        // Lock was released so a retry or someone else can try
        $this->assertNull(Cache::get($setup['meeting']->hostCacheKey()));
    }

    public function test_meeting_serialization_includes_host_user_id_and_never_leaks_start_url(): void
    {
        $setup = $this->createMeetingSetup();
        Cache::put($setup['meeting']->hostCacheKey(), $setup['manager']->id, now()->addHour());

        Sanctum::actingAs($setup['manager'], ['*']);
        $response = $this->getJson("/api/workspaces/{$setup['workspace']->id}/meetings");

        $response->assertOk();
        $meetingData = $response->json('meetings.data.0');

        $this->assertEquals($setup['manager']->id, $meetingData['host_user_id']);
        $this->assertArrayNotHasKey('start_url', $meetingData);
    }

    public function test_meeting_lifecycle_cleans_up_host_cache(): void
    {
        $setup = $this->createMeetingSetup();
        $key = $setup['meeting']->hostCacheKey();

        // 1. Reschedule clears cache
        Cache::put($key, $setup['manager']->id, now()->addHour());
        Sanctum::actingAs($setup['manager'], ['*']);
        $this->putJson("/api/workspaces/{$setup['workspace']->id}/meetings/{$setup['meeting']->id}", [
            'scheduled_at' => now()->addDays(2)->toIso8601String(),
        ])->assertOk();
        $this->assertNull(Cache::get($key));

        // 2. Complete clears cache
        Cache::put($key, $setup['manager']->id, now()->addHour());
        $this->patchJson("/api/meetings/{$setup['meeting']->id}/complete")->assertOk();
        $this->assertNull(Cache::get($key));

        // 3. Cancel clears cache
        $m2 = Meeting::create([
            'workspace_id' => $setup['workspace']->id,
            'title' => 'M2',
            'scheduled_at' => now()->addDay(),
            'status' => 'scheduled',
            'created_by' => $setup['manager']->id,
            'zoom_meeting_id' => '999',
            'link' => 'https://zoom.us/j/999',
        ]);
        Cache::put($m2->hostCacheKey(), $setup['manager']->id, now()->addHour());
        $this->patchJson("/api/meetings/{$m2->id}/cancel")->assertOk();
        $this->assertNull(Cache::get($m2->hostCacheKey()));
    }

    public function test_host_claim_expires_at_calculation(): void
    {
        $meeting = new Meeting([
            'scheduled_at' => now()->addHour(),
            'duration_minutes' => 60,
        ]);

        // Scheduled in 1 hour + 60 min duration + 2 hours = now + 4 hours
        $expiresAt = $meeting->hostClaimExpiresAt();
        $this->assertTrue($expiresAt->gt(now()->addHours(3)));
    }
}
