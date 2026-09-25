<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;

/**
 * plans/notifications-badges-toasts-plan.md ن13 — GET /notifications has no
 * limit and nothing ever deleted an old, already-read notification, so the
 * table only ever grows. Scheduled daily in routes/console.php.
 */
class PruneReadNotifications extends Command
{
    protected $signature = 'notifications:prune';
    protected $description = 'Delete read notifications older than 90 days';

    public function handle(): void
    {
        $deleted = DatabaseNotification::whereNotNull('read_at')
            ->where('read_at', '<', now()->subDays(90))
            ->delete();

        $this->info("Pruned {$deleted} read notification(s) older than 90 days.");
    }
}
