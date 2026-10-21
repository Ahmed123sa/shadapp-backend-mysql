<?php

namespace App\Support;

use App\Models\Meeting;
use App\Notifications\MeetingReminderNotification;

/**
 * Everyone who hears about a meeting's alerts: the manager who created it,
 * the workspace's manager, the client, and the staff assigned to the client
 * with can_view_meetings. Same people meetings:send-reminders reaches.
 */
class MeetingNotifier
{
    public static function everyone(Meeting $meeting, string $kind): int
    {
        $sent = 0;

        // An assistant who created the meeting is reached through the
        // manager's fan-out below, not directly.
        $creator = $meeting->creator?->responsibleManager();
        if ($creator && !$creator->isActive()) {
            $creator = null;
        }
        if ($creator) {
            $creator->notify(new MeetingReminderNotification($meeting, $kind));
            $sent++;
        }

        $manager = $meeting->workspace?->manager;
        if ($manager && !$manager->isActive()) {
            $manager = null;
        }
        if ($manager && $manager->id !== ($creator?->id)) {
            $manager->notify(new MeetingReminderNotification($meeting, $kind));
            $sent++;
        }

        $client = $meeting->workspace?->client;
        if ($client) {
            $client->notify(new MeetingReminderNotification($meeting, $kind));
            $sent++;
        }

        $sent += StaffNotifier::meetingReminder($meeting, [$creator?->id, $manager?->id], $kind);

        return $sent;
    }
}
