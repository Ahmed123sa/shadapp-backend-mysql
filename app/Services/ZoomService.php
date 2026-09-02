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

        return $response->json() ?? [];
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

    /**
     * Verifies a Zoom webhook against the Webhook Secret Token.
     *
     * Zoom signs the message "v0:{timestamp}:{raw body}" — NOT the body on its
     * own — and sends the result as "v0={hash}" in x-zm-signature, with the
     * timestamp in x-zm-request-timestamp. Hashing only the body (as this used
     * to) can never match a real Zoom request.
     *
     * Fails CLOSED when the secret is missing. This previously returned true
     * in that case, which meant a deployment that forgot
     * ZOOM_WEBHOOK_SECRET_TOKEN (it ships empty in .env.example) silently
     * accepted any unauthenticated POST to /api/webhooks/zoom — enough to
     * close meetings, inject attendees, or point recording_url at an
     * arbitrary URL that users are then shown as "the recording".
     */
    public function verifyWebhookSignature(string $payload, string $signature, ?string $timestamp = null): bool
    {
        $secret = config('services.zoom.webhook_secret');
        if (!$secret) {
            Log::error('Zoom: ZOOM_WEBHOOK_SECRET_TOKEN is not configured — rejecting webhook');
            return false;
        }

        if ($signature === '' || $timestamp === null || $timestamp === '') {
            return false;
        }

        // Replay guard: a captured request stays valid forever without this.
        // Zoom's own guidance is a 5-minute window.
        if (abs(time() - (int) $timestamp) > 300) {
            Log::warning('Zoom: webhook timestamp outside the allowed window', [
                'timestamp' => $timestamp,
            ]);
            return false;
        }

        $expected = 'v0=' . hash_hmac('sha256', "v0:{$timestamp}:{$payload}", $secret);

        return hash_equals($expected, $signature);
    }
}
