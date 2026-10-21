<?php

namespace App\Listeners;

use App\Events\MeetingCreated;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class CreateMeetingChatMessage
{
    public function handleMeetingCreated(MeetingCreated $event): void
    {
        $meeting = $event->meeting;
        $workspace = $meeting->workspace;

        if (!$workspace) {
            Log::warning('MeetingCreated: no workspace found for meeting #' . $meeting->id);
            return;
        }

        $creator = $meeting->creator;

        $scheduledAt = null;
        if ($meeting->scheduled_at) {
            try {
                $dt = $meeting->scheduled_at instanceof \Illuminate\Support\Carbon
                    ? $meeting->scheduled_at
                    : \Illuminate\Support\Carbon::parse($meeting->scheduled_at);
                $scheduledAt = $dt->toIso8601String();
            } catch (\Exception $e) {
                $scheduledAt = $meeting->scheduled_at;
            }
        }

        $messageText = '📹 ' . ($meeting->title ?? 'اجتماع جديد');

        $message = $workspace->chatMessages()->create([
            'sender_type' => $creator ? get_class($creator) : User::class,
            'sender_id' => $meeting->created_by,
            'message' => $messageText,
            'type' => 'meeting',
            'metadata' => [
                'meeting_id' => $meeting->id,
                'title' => $meeting->title,
                'scheduled_at' => $scheduledAt,
                'duration_minutes' => $meeting->duration_minutes,
                'link' => $meeting->link,
                'passcode' => $meeting->passcode,
                'status' => $meeting->status,
            ],
        ]);

        // Live update + push to the client, the same way a rescheduled
        // meeting's card is delivered (Meeting::postRescheduledChatCard). Before
        // this the new card only showed up when the chat was next opened.
        try {
            broadcast(new \App\Domains\Chat\MessageSent($message));
        } catch (\Throwable $e) {
            Log::warning('Meeting card broadcast failed (non-critical): ' . $e->getMessage());
        }
        if ($workspace->client) {
            try {
                $workspace->client->notify(new \App\Notifications\ChatMessageSentNotification($message));
            } catch (\Throwable $e) {
                Log::warning('Meeting card notification failed: ' . $e->getMessage());
            }
        }
    }
}
