<?php

namespace App\Support;

use App\Models\Approval;
use App\Models\User;
use App\Notifications\ApprovalRequestedNotification;
use Illuminate\Support\Facades\Log;

/**
 * Who hears about a new approval request, however it was raised (the Approvals
 * tab or a chat message marked as needing approval).
 *
 * The client — the one person who has to respond — always; the workspace
 * manager only when someone else (a super admin) raised the request in their
 * workspace. Super admins are not told.
 */
class ApprovalNotifier
{
    public static function requested(Approval $approval, ?object $actor): void
    {
        $workspace = $approval->workspace;
        if (! $workspace) {
            return;
        }

        $recipients = collect();
        if ($workspace->client) {
            $recipients->push($workspace->client);
        }
        $manager = $workspace->manager;
        if ($manager instanceof User && $manager->id !== $actor?->id && $manager->isActive()) {
            $recipients->push($manager);
        }

        foreach ($recipients as $user) {
            try {
                $user->notify(new ApprovalRequestedNotification($approval));
            } catch (\Throwable $e) {
                Log::warning('Failed to send approval requested notification: ' . $e->getMessage());
            }
        }
    }
}
