<?php

namespace App\Support;

use App\Models\ChatMessage;
use App\Models\Approval;
use App\Models\Meeting;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\ApprovalRespondedNotification;
use App\Notifications\ChatMessageSentNotification;
use App\Notifications\MeetingReminderNotification;
use App\Notifications\StaffAssignedNotification;
use Illuminate\Support\Facades\Log;

/**
 * Which events reach company staff (STAFF_TEAMS_PLAN.md §٤), and to whom:
 * only the staff assigned to that client's workspace, only active ones, and
 * only these four things — a client's chat message, a client's answer to an
 * approval request, a meeting reminder, being assigned. No contracts,
 * payments, files or birthdays (User::canSeeNotificationType enforces the
 * same list on the read side).
 *
 * A failure to notify never breaks the action that triggered it.
 */
class StaffNotifier
{
    /** A message written by the client side (client or sub-user). */
    public static function chatMessage(ChatMessage $message): void
    {
        $workspace = $message->workspace;

        foreach (self::assigned($workspace) as $staff) {
            self::safely(function () use ($staff, $message, $workspace) {
                // Same push throttle as the manager's: one push per 5 minutes
                // per conversation until they open the chat; the stored
                // notification and the live event still go out every time.
                $skipPush = $staff->notifications()
                    ->where('data->type', 'chat')
                    ->where('data->workspace_id', $workspace->id)
                    ->whereNull('read_at')
                    ->where('created_at', '>=', now()->subMinutes(5))
                    ->exists();

                $staff->notify(new ChatMessageSentNotification($message, $skipPush));
            });
        }
    }

    /** The client answered an approval request. $exceptUserId is told already. */
    public static function approvalResponded(Approval $approval, ?int $exceptUserId = null): void
    {
        foreach (self::assigned($approval->workspace) as $staff) {
            if ($exceptUserId !== null && $staff->id === $exceptUserId) {
                continue;
            }
            self::safely(fn () => $staff->notify(new ApprovalRespondedNotification($approval)));
        }
    }

    /** @param array<int|null> $exceptUserIds people who got this reminder directly. */
    public static function meetingReminder(Meeting $meeting, array $exceptUserIds = []): int
    {
        $sent = 0;
        foreach (self::assigned($meeting->workspace) as $staff) {
            if (in_array($staff->id, $exceptUserIds, true)) {
                continue;
            }
            self::safely(function () use ($staff, $meeting, &$sent) {
                $staff->notify(new MeetingReminderNotification($meeting));
                $sent++;
            });
        }

        return $sent;
    }

    /** Told to the staff member who was just added to this client. */
    public static function wasAssigned(Workspace $workspace, User $staff): void
    {
        self::safely(fn () => $staff->notify(new StaffAssignedNotification($workspace)));
    }

    /** @return \Illuminate\Support\Collection<int, User> */
    private static function assigned(?Workspace $workspace)
    {
        if (! $workspace) {
            return collect();
        }

        return $workspace->staff()->get()->filter(fn (User $u) => $u->isStaffMember() && $u->isActive())->values();
    }

    private static function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::warning('Staff notification failed: ' . $e->getMessage());
        }
    }
}
