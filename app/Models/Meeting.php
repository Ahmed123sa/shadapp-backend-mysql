<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Meeting extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id', 'contract_id', 'approval_id', 'title', 'zoom_meeting_id', 'link', 'passcode',
        'scheduled_at', 'duration_minutes', 'status', 'notes', 'recording_url', 'created_by', 'ended_at',
        'zoom_started_at', 'zoom_ended_at', 'zoom_attendees',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'ended_at' => 'datetime',
            'zoom_started_at' => 'datetime',
            'zoom_ended_at' => 'datetime',
            'zoom_attendees' => 'array',
            'duration_minutes' => 'integer',
        ];
    }

    // 23 Sept 2026 — chat-card helpers used by App\Observers\MeetingObserver,
    // which keeps a meeting's chat card(s) in sync with the meeting and posts
    // a fresh card when it's rescheduled. See that class for the why.
    public const CHAT_SNAPSHOT_FIELDS = ['status', 'title', 'scheduled_at', 'duration_minutes', 'link', 'passcode'];

    /**
     * Same shape CreateMeetingChatMessage writes (scheduled_at as ISO 8601),
     * so the mobile MeetingChip parses it identically.
     */
    public function chatSnapshot(): array
    {
        return [
            'meeting_id' => $this->id,
            'title' => $this->title,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'duration_minutes' => $this->duration_minutes,
            // A cancelled meeting's join details are hidden from the chat
            // card (MeetingObserver's original behaviour).
            'link' => $this->status === 'cancelled' ? null : $this->link,
            'passcode' => $this->status === 'cancelled' ? null : $this->passcode,
            'status' => $this->status,
        ];
    }

    /**
     * Rewrites the snapshot fields (and the title in the message text) on
     * every chat card for this meeting. Filtered in PHP rather than with a
     * JSON-path where clause so it behaves identically on Postgres and
     * MySQL; a workspace only has a handful of meeting messages.
     */
    public function syncChatMessages(): void
    {
        $snapshot = $this->chatSnapshot();

        ChatMessage::where('workspace_id', $this->workspace_id)
            ->where('type', 'meeting')
            ->get()
            ->filter(fn (ChatMessage $message) => (int) ($message->metadata['meeting_id'] ?? 0) === $this->id)
            ->each(function (ChatMessage $message) use ($snapshot) {
                $metadata = array_merge($message->metadata ?? [], $snapshot);
                $message->update([
                    'metadata' => $metadata,
                    'message' => $this->chatCardText(!empty($metadata['rescheduled'])),
                ]);
            });
    }

    private function chatCardText(bool $rescheduled): string
    {
        return $rescheduled
            ? '📅 تم تغيير ميعاد اجتماع: ' . $this->title
            : '📹 ' . ($this->title ?? 'اجتماع جديد');
    }

    public function postRescheduledChatCard(): void
    {
        $workspace = $this->workspace;
        if (!$workspace) {
            return;
        }

        // Whoever made the change, if it's staff; otherwise the meeting's
        // creator, then the workspace's manager. sender is non-nullable.
        $actor = auth()->user();
        $sender = $actor instanceof User ? $actor : ($this->creator ?? $workspace->manager);
        if (!$sender) {
            \Illuminate\Support\Facades\Log::warning('Meeting #' . $this->id . ' rescheduled but no sender for the chat card');
            return;
        }

        $message = $workspace->chatMessages()->create([
            'sender_type' => get_class($sender),
            'sender_id' => $sender->id,
            'message' => $this->chatCardText(true),
            'type' => 'meeting',
            'metadata' => $this->chatSnapshot() + ['rescheduled' => true],
        ]);

        // Same realtime + push delivery ChatController::store() does for a
        // normal staff message; neither happens automatically on create.
        try {
            broadcast(new \App\Domains\Chat\MessageSent($message));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Reschedule card broadcast failed (non-critical): ' . $e->getMessage());
        }
        if ($workspace->client) {
            try {
                $workspace->client->notify(new \App\Notifications\ChatMessageSentNotification($message));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Reschedule card notification failed: ' . $e->getMessage());
            }
        }
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class);
    }
}
