<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ZoomService
{
    private ?string $accessToken = null;

    public static function isConfigured(): bool
    {
        return !empty(config('services.zoom.client_id'));
    }

    protected function getAccessToken(): string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        $this->accessToken = Cache::remember('zoom_access_token', 3300, function () {
            $response = Http::asForm()->post('https://zoom.us/oauth/token', [
                'grant_type' => 'account_credentials',
                'account_id' => config('services.zoom.account_id'),
                'client_id' => config('services.zoom.client_id'),
                'client_secret' => config('services.zoom.client_secret'),
            ]);

            if ($response->failed()) {
                Log::error('Zoom: Failed to obtain access token', [
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);
                throw new \RuntimeException('Zoom OAuth token request failed');
            }

            return $response->json('access_token');
        });

        return $this->accessToken;
    }

    protected function api(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withToken($this->getAccessToken())
            ->withHeaders(['Content-Type' => 'application/json']);
    }

    public function createMeeting(string $title, string $scheduledAt, int $duration): array
    {
        $response = $this->api()->post('https://api.zoom.us/v2/users/me/meetings', [
            'topic' => $title,
            'type' => 2,
            'start_time' => $scheduledAt,
            'duration' => $duration,
            'timezone' => config('app.timezone', 'UTC'),
            'settings' => ['host_video' => true, 'participant_video' => true],
        ]);

        if ($response->failed()) {
            Log::error('Zoom: Failed to create meeting', [
                'status' => $response->status(),
                'response' => $response->body(),
            ]);
            throw new \RuntimeException('Zoom meeting creation failed');
        }

        return $response->json();
    }

    public function updateMeeting(string $zoomMeetingId, array $data): array
    {
        $response = $this->api()->patch(
            "https://api.zoom.us/v2/meetings/{$zoomMeetingId}",
            $data
        );

        if ($response->failed()) {
            Log::error('Zoom: Failed to update meeting', [
                'zoom_meeting_id' => $zoomMeetingId,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);
            throw new \RuntimeException('Zoom meeting update failed');
        }

        return $response->json();
    }

    public function deleteMeeting(string $zoomMeetingId): bool
    {
        $response = $this->api()->delete(
            "https://api.zoom.us/v2/meetings/{$zoomMeetingId}"
        );

        if ($response->failed()) {
            Log::error('Zoom: Failed to delete meeting', [
                'zoom_meeting_id' => $zoomMeetingId,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);
            throw new \RuntimeException('Zoom meeting deletion failed');
        }

        return $response->successful();
    }

    public function getMeeting(string $zoomMeetingId): array
    {
        $response = $this->api()->get(
            "https://api.zoom.us/v2/meetings/{$zoomMeetingId}"
        );

        if ($response->failed()) {
            Log::error('Zoom: Failed to get meeting', [
                'zoom_meeting_id' => $zoomMeetingId,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);
            throw new \RuntimeException('Zoom meeting fetch failed');
        }

        return $response->json();
    }

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        $secret = config('services.zoom.webhook_secret');
        if (!$secret) {
            return true;
        }

        $expected = hash_hmac('sha256', $payload, $secret);
        return hash_equals($expected, $signature);
    }
}
