<?php

namespace App\Observers;

use App\Models\Meeting;
use App\Models\ChatMessage;

class MeetingObserver
{
    public function updated(Meeting $meeting): void
    {
        if (!$meeting->isDirty('status')) {
            return;
        }

        $newStatus = $meeting->status;
        if (!in_array($newStatus, ['scheduled', 'completed', 'cancelled'], true)) {
            return;
        }

        ChatMessage::where('type', 'meeting')
            ->where('metadata->meeting_id', $meeting->id)
            ->get()
            ->each(function (ChatMessage $m) use ($newStatus) {
                $meta = is_array($m->metadata) ? $m->metadata : ($m->metadata ? json_decode(json_encode($m->metadata), true) : []);
                $meta['status'] = $newStatus;
                if ($newStatus === 'cancelled') {
                    $meta['link'] = null;
                    $meta['passcode'] = null;
                }
                $m->update(['metadata' => $meta]);
            });
    }

    public function deleted(Meeting $meeting): void
    {
        ChatMessage::where('type', 'meeting')
            ->where('metadata->meeting_id', $meeting->id)
            ->delete();
    }
}
