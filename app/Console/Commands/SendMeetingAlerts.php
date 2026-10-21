<?php

namespace App\Console\Commands;

use App\Models\Meeting;
use App\Support\MeetingNotifier;
use Illuminate\Console\Command;

/**
 * Runs every minute. Two one-off alerts per scheduled meeting, on top of the
 * half-hour reminder (meetings:send-reminders):
 *   - "starts in 5 minutes", once the start is 5 minutes away or less;
 *   - "has started", once the start time has come (up to 10 minutes late,
 *     so a scheduler that was down for a while doesn't announce old meetings).
 * soon_notified_at / start_notified_at make each go out once; moving the
 * meeting clears them (Meeting::booted).
 */
class SendMeetingAlerts extends Command
{
    protected $signature = 'meetings:send-alerts';
    protected $description = 'Send the "starts in 5 minutes" and "has started" meeting alerts';

    public function handle(): void
    {
        $now = now();
        $sent = 0;

        $starting = Meeting::where('status', 'scheduled')
            ->whereNull('start_notified_at')
            ->where('scheduled_at', '<=', $now)
            ->where('scheduled_at', '>=', $now->copy()->subMinutes(10))
            ->get();
        foreach ($starting as $meeting) {
            // Marked first (no model events, so no chat-card sync) so a slow
            // or failing push can't make the next run send it again.
            Meeting::whereKey($meeting->id)->update([
                'start_notified_at' => $now,
                'soon_notified_at' => $meeting->soon_notified_at ?? $now,
            ]);
            $sent += MeetingNotifier::everyone($meeting, 'started');
        }

        $soon = Meeting::where('status', 'scheduled')
            ->whereNull('soon_notified_at')
            ->where('scheduled_at', '>', $now)
            ->where('scheduled_at', '<=', $now->copy()->addMinutes(5))
            ->get();
        foreach ($soon as $meeting) {
            Meeting::whereKey($meeting->id)->update(['soon_notified_at' => $now]);
            $sent += MeetingNotifier::everyone($meeting, 'soon');
        }

        $this->info("Sent {$sent} meeting alert(s).");
    }
}
