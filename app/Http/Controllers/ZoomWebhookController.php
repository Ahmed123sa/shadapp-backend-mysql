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

        // Verify webhook signature. Zoom signs "v0:{timestamp}:{body}", so the
        // timestamp header is part of the signed message and has to be passed
        // through — see ZoomService::verifyWebhookSignature.
        $signature = $request->header('x-zm-signature') ?? '';
        $timestamp = $request->header('x-zm-request-timestamp');
        $body = $request->getContent();
        if (!app(ZoomService::class)->verifyWebhookSignature($body, $signature, $timestamp)) {
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
            'recording.ready', 'recording.completed' => $this->handleRecordingReady($object),
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

    /**
     * A stable key for one participant, used to pair a "left" event with the
     * "joined" entry it belongs to.
     *
     * Email alone is not enough: Zoom omits it for participants who join
     * without signing in, and matching `null === null` pairs a leave event
     * with whichever anonymous attendee happens to be first in the list.
     * Prefers the identifiers Zoom actually guarantees, and returns null when
     * nothing identifies the participant at all.
     */
    protected function participantKey(array $participant): ?string
    {
        foreach (['participant_uuid', 'user_id', 'id', 'email'] as $field) {
            $value = $participant[$field] ?? null;
            if ($value !== null && $value !== '') {
                return $field . ':' . $value;
            }
        }

        return null;
    }

    protected function handleParticipantJoined(array $payload): void
    {
        $zoomMeetingId = $payload['payload']['object']['id'] ?? null;
        $participant = $payload['payload']['object']['participant'] ?? null;
        if (!$zoomMeetingId || !$participant) return;

        $meeting = Meeting::where('zoom_meeting_id', $zoomMeetingId)->first();
        if (!$meeting) return;

        $attendees = $meeting->zoom_attendees ?? [];
        $key = $this->participantKey($participant);

        // Zoom re-sends participant_joined every time someone rejoins after a
        // drop, which is routine on a flaky connection. Appending blindly
        // turned one attendee into one row per reconnect.
        if ($key !== null) {
            foreach ($attendees as $existing) {
                if (($existing['key'] ?? null) === $key && !isset($existing['left_at'])) {
                    return;
                }
            }
        }

        $attendees[] = [
            'key' => $key,
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

        $key = $this->participantKey($participant);
        if ($key === null) {
            // Leaving the entry open is the lesser error: closing an
            // arbitrary one records a departure time against the wrong person.
            Log::info('Zoom: participant_left carried no usable identifier', [
                'meeting_id' => $meeting->id,
            ]);
            return;
        }

        $attendees = $meeting->zoom_attendees ?? [];

        foreach ($attendees as &$attendee) {
            if (($attendee['key'] ?? null) === $key && !isset($attendee['left_at'])) {
                $attendee['left_at'] = now()->toIso8601String();
                if (isset($attendee['joined_at'])) {
                    $joined = \Carbon\Carbon::parse($attendee['joined_at']);
                    $attendee['duration_min'] = (int) abs($joined->diffInMinutes(now()));
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
        if (!$zoomMeetingId) return;

        $meeting = Meeting::where('zoom_meeting_id', $zoomMeetingId)->first();
        if (!$meeting) return;

        $downloadUrl = null;
        foreach ($object['recording_files'] ?? [] as $file) {
            if (!empty($file['download_url'])) {
                $downloadUrl = $file['download_url'];
                break;
            }
        }

        if (!$downloadUrl) return;

        $meeting->update(['recording_url' => $downloadUrl]);

        Log::info('Zoom: Recording URL saved', ['meeting_id' => $meeting->id]);
    }
}
