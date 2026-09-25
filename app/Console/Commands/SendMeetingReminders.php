<?php

namespace App\Console\Commands;

use App\Models\Meeting;
use App\Notifications\MeetingReminderNotification;
use Illuminate\Console\Command;

class SendMeetingReminders extends Command
{
    protected $signature = 'meetings:send-reminders';
    protected $description = 'Send reminders for upcoming meetings';

    public function handle(): void
    {
        $meetings = Meeting::where('status', 'scheduled')
            ->where('scheduled_at', '>=', now())
            ->where('scheduled_at', '<=', now()->addMinutes(30))
            ->get();

        $sent = 0;

        foreach ($meetings as $meeting) {
            $creator = $meeting->creator;
            // created_by is fixed history — it can point at a manager
            // deactivated after the client moved to someone else via
            // transfer. A deactivated account shouldn't get reminders.
            if ($creator && !$creator->isActive()) {
                $creator = null;
            }
            if ($creator) {
                $creator->notify(new MeetingReminderNotification($meeting));
                $sent++;
            }

            $manager = $meeting->workspace?->manager;
            if ($manager && !$manager->isActive()) {
                $manager = null;
            }
            if ($manager && $manager->id !== ($creator?->id)) {
                $manager->notify(new MeetingReminderNotification($meeting));
                $sent++;
            }

            // plans/notifications-badges-toasts-plan.md ن3 — $client->id is a
            // primary key on the clients table; $creator/$manager->id are
            // primary keys on users. Comparing them across tables was
            // comparing unrelated id spaces — a client and a manager sharing
            // the same numeric id (e.g. both id 5, on any two rows in two
            // different tables) silently skipped notifying the client every
            // time their meeting reminder fired. A Client can never actually
            // be the same record as the meeting's creator or manager, so
            // there was nothing for this comparison to correctly guard
            // against in the first place.
            $client = $meeting->workspace?->client;
            if ($client) {
                $client->notify(new MeetingReminderNotification($meeting));
                $sent++;
            }
        }

        $this->info("Sent {$sent} meeting reminder(s).");
    }
}
