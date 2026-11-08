<?php

namespace App\Listeners;

use App\Events\MeetingCreated;
use App\Models\Meeting;
use App\Support\SuperAdminMeetingNotifier;

class NotifySuperAdminOfMeeting
{
    public function handleMeetingCreated(MeetingCreated $event): void
    {
        $meeting = $event->meeting;

        // A meeting set to start within 15 minutes gets the creation notice
        // only: the reminder would arrive seconds later saying the same thing.
        if ($meeting->scheduled_at && $meeting->scheduled_at->lte(now()->addMinutes(15))) {
            Meeting::whereKey($meeting->id)->update(['sa_reminded_at' => now()]);
        }

        SuperAdminMeetingNotifier::created($meeting);
    }
}
