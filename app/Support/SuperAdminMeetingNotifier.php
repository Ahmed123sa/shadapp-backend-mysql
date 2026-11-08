<?php

namespace App\Support;

use App\Models\Meeting;
use App\Models\User;
use App\Notifications\MeetingReminderNotification;

/**
 * What the super admin hears about meetings other people run: a new meeting,
 * a reminder 15 minutes before it starts, and a cancellation. Whoever made or
 * cancelled the meeting is not told about their own action (and a super admin
 * who created it already gets the creator's reminders from MeetingNotifier).
 * Reuses the 'meeting_reminder' type, so the apps already know how to show it
 * and where it leads.
 */
class SuperAdminMeetingNotifier
{
    /** A new meeting. */
    public static function created(Meeting $meeting): int
    {
        return self::send($meeting, 'created', $meeting->created_by, $meeting->creator?->name);
    }

    /** The 15-minute reminder (meetings:send-alerts). */
    public static function soon(Meeting $meeting): int
    {
        return self::send($meeting, 'in15', $meeting->created_by);
    }

    /** A cancelled meeting; $actor is whoever cancelled it. */
    public static function cancelled(Meeting $meeting, ?User $actor): int
    {
        return self::send($meeting, 'cancelled', $actor?->id, $actor?->name);
    }

    private static function send(Meeting $meeting, string $kind, ?int $exceptUserId, ?string $by = null): int
    {
        $sent = 0;

        $admins = User::where('role', User::ROLE_SUPER_ADMIN)->get()
            ->filter(fn (User $u) => $u->isActive() && $u->id !== $exceptUserId);

        foreach ($admins as $admin) {
            $admin->notify(new MeetingReminderNotification($meeting, $kind, $by));
            $sent++;
        }

        return $sent;
    }
}
