<?php

namespace App\Http\Controllers;

use App\Models\Meeting;
use App\Services\ZoomService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ZoomWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();

        // Zoom endpoint URL validation (first-time webhook setup)
        if (isset($payload['endpoint_url_validation'])) {
            $plainToken = $payload['endpoint_url_validation']['plainToken'] ?? '';
            $secretToken = config('services.zoom.webhook_secret');

            return response()->json([
                'plainToken' => $plainToken,
                'encryptedToken' => hash_hmac('sha256', $plainToken, $secretToken),
            ]);
        }

        // Verify webhook signature
        $signature = $request->header('x-zm-signature') ?? '';
        $body = $request->getContent();
        if (!app(ZoomService::class)->verifyWebhookSignature($body, $signature)) {
            Log::warning('Zoom: Invalid webhook signature');
            return response()->json(['status' => 'invalid_signature'], 401);
        }

        $event = $payload['event'] ?? '';
        $object = $payload['payload']['object'] ?? [];

        Log::info('Zoom webhook received', ['event' => $event, 'meeting_id' => $object['id'] ?? null]);

        match ($event) {
            'meeting.ended' => $this->handleMeetingEnded($object),
            'meeting.participant_joined' => $this->handleParticipantJoined($payload),
            'meeting.participant_left' => $this->handleParticipantLeft($payload),
            'recording.ready' => $this->handleRecordingReady($object),
            default => null,
        };

        return response()->json(['status' => 'ok']);
    }

    protected function handleMeetingEnded(array $object): void
    {
        $zoomMeetingId = $object['id'] ?? null;
        if (!$zoomMeetingId) return;

        $meeting = Meeting::where('zoom_meeting_id', $zoomMeetingId)->first();
        if (!$meeting || $meeting->status !== 'scheduled') return;

        $meeting->update([
            'status' => 'completed',
            'ended_at' => now(),
            'zoom_ended_at' => now(),
        ]);

        Log::info('Zoom: Meeting auto-completed via webhook', ['meeting_id' => $meeting->id]);
    }

    protected function handleParticipantJoined(array $payload): void
    {
        $zoomMeetingId = $payload['payload']['object']['id'] ?? null;
        $participant = $payload['payload']['object']['participant'] ?? null;
        if (!$zoomMeetingId || !$participant) return;

        $meeting = Meeting::where('zoom_meeting_id', $zoomMeetingId)->first();
        if (!$meeting) return;

        $attendees = $meeting->zoom_attendees ?? [];
        $attendees[] = [
            'name' => $participant['user_name'] ?? 'Unknown',
            'email' => $participant['email'] ?? null,
            'joined_at' => now()->toIso8601String(),
        ];

        $meeting->update(['zoom_attendees' => $attendees]);
    }

    protected function handleParticipantLeft(array $payload): void
    {
        $zoomMeetingId = $payload['payload']['object']['id'] ?? null;
        $participant = $payload['payload']['object']['participant'] ?? null;
        if (!$zoomMeetingId || !$participant) return;

        $meeting = Meeting::where('zoom_meeting_id', $zoomMeetingId)->first();
        if (!$meeting) return;

        $attendees = $meeting->zoom_attendees ?? [];
        $email = $participant['email'] ?? null;

        foreach ($attendees as &$attendee) {
            if (($attendee['email'] ?? null) === $email && !isset($attendee['left_at'])) {
                $attendee['left_at'] = now()->toIso8601String();
                if (isset($attendee['joined_at'])) {
                    $joined = \Carbon\Carbon::parse($attendee['joined_at']);
                    $attendee['duration_min'] = $joined->diffInMinutes(now());
                }
                break;
            }
        }
        unset($attendee);

        $meeting->update(['zoom_attendees' => $attendees]);
    }

    protected function handleRecordingReady(array $object): void
    {
        $zoomMeetingId = $object['id'] ?? null;
        $playUrl = $object['play_url'] ?? null;
        if (!$zoomMeetingId || !$playUrl) return;

        $meeting = Meeting::where('zoom_meeting_id', $zoomMeetingId)->first();
        if (!$meeting) return;

        $meeting->update(['recording_url' => $playUrl]);

        Log::info('Zoom: Recording URL saved', ['meeting_id' => $meeting->id]);
    }
}
