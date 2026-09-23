<?php

namespace App\Observers;

use App\Models\Meeting;
use App\Models\ChatMessage;

class MeetingObserver
{
    /**
     * Keeps a meeting's chat card(s) in sync with the meeting.
     *
     * 23 Sept 2026 — this used to sync only `status` (nulling link/passcode on
     * cancel), so a retitled or rescheduled meeting still showed its original
     * title/time in the chat. It now syncs the whole snapshot
     * CreateMeetingChatMessage writes (Meeting::chatSnapshot()), matching the
     * cards in PHP rather than with a JSON-path where so it behaves the same
     * on Postgres and MySQL.
     *
     * A reschedule of a still-scheduled meeting also posts a *new* card at the
     * bottom of the chat and notifies the client — the old card alone is
     * usually far up the conversation and wouldn't be noticed. It's a card,
     * not a text message, so each viewer's app renders the time in their own
     * timezone; the server only knows UTC.
     */
    public function updated(Meeting $meeting): void
    {
        if ($meeting->wasChanged(Meeting::CHAT_SNAPSHOT_FIELDS)) {
            $meeting->syncChatMessages();
        }

        if ($meeting->wasChanged('scheduled_at') && $meeting->status === 'scheduled') {
            $meeting->postRescheduledChatCard();
        }
    }

    public function deleted(Meeting $meeting): void
    {
        ChatMessage::where('type', 'meeting')
            ->where('metadata->meeting_id', $meeting->id)
            ->delete();
    }
}
