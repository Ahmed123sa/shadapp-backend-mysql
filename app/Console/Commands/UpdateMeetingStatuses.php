<?php

namespace App\Console\Commands;

use App\Models\Meeting;
use Illuminate\Console\Command;

class UpdateMeetingStatuses extends Command
{
    protected $signature = 'meetings:update-statuses';
    protected $description = 'Auto-complete meetings whose time has passed';

    public function handle(): int
    {
        $meetings = Meeting::where('status', 'scheduled')
            ->where('scheduled_at', '<=', now())
            ->get();

        $count = 0;

        foreach ($meetings as $meeting) {
            // Prefer Zoom webhook data if available
            if ($meeting->zoom_ended_at) {
                $meeting->update([
                    'status' => 'completed',
                    'ended_at' => $meeting->zoom_ended_at,
                ]);
                $count++;
                continue;
            }

            // Fallback: time-based auto-complete
            $endTime = $meeting->scheduled_at->copy()->addMinutes($meeting->duration_minutes ?? 30);

            if (now()->greaterThanOrEqualTo($endTime)) {
                $meeting->update([
                    'status' => 'completed',
                    'ended_at' => $endTime,
                ]);
                $count++;
            }
        }

        if ($count > 0) {
            $this->info("Completed {$count} meeting(s).");
        }

        return 0;
    }
}
