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

    // 23 Sept 2026 — CreateMeetingChatMessage writes a snapshot of the
    // meeting (including its status) into the chat message's metadata when
    // the meeting is created, and nothing ever updated it afterwards: a
    // meeting completed or cancelled (by MeetingController::complete()/
    // cancel()/update(), or auto-completed by the meetings:update-statuses
    // command) still showed as scheduled in the chat. Hooked here rather
    // than in each of those four places so any status change, from any
    // path, keeps the chat card in sync.
    protected static function booted(): void
    {
        static::updated(function (Meeting $meeting) {
            if ($meeting->wasChanged('status')) {
                $meeting->syncChatMessageStatus();
            }
        });
    }

    /**
     * Copies the current status into the metadata of this meeting's chat
     * message(s). Filtered in PHP rather than with a JSON-path where clause
     * so it behaves identically on Postgres and MySQL; a workspace only has a
     * handful of meeting messages.
     */
    public function syncChatMessageStatus(): void
    {
        ChatMessage::where('workspace_id', $this->workspace_id)
            ->where('type', 'meeting')
            ->get()
            ->filter(fn (ChatMessage $message) => (int) ($message->metadata['meeting_id'] ?? 0) === $this->id)
            ->each(function (ChatMessage $message) {
                $metadata = $message->metadata;
                $metadata['status'] = $this->status;
                $message->update(['metadata' => $metadata]);
            });
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
