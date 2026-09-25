<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('contracts:send-reminders')->dailyAt('09:00');
Schedule::command('meetings:send-reminders')->everyThirtyMinutes();
Schedule::command('meetings:update-statuses')->everyFiveMinutes();
Schedule::command('payments:send-reminders')->dailyAt('09:00');
Schedule::command('birthdays:send-reminders')->dailyAt('09:00');

// Runs at 03:00, away from the 09:00 reminder burst — mysqldump/pg_dump hold
// a consistent read of the whole database, so it is worth keeping off the
// busiest minute of the day.
// withoutOverlapping matters here more than for the reminders: a large dump
// could still be running when the next one is due, and two concurrent dumps
// would compete for the same disk and I/O for no benefit.
Schedule::command('db:backup')->dailyAt('03:00')->withoutOverlapping();

// Staggered after the backup so the two aren't competing, and so the dump
// captures the day's attempts before they're pruned rather than after.
Schedule::command('login-attempts:prune')->dailyAt('04:00')->withoutOverlapping();

// plans/notifications-badges-toasts-plan.md ن13 — GET /notifications has no
// limit and nothing else ever deletes an old, already-read row, so the table
// only grows. Staggered 30 minutes after login-attempts:prune for the same
// reason that one is staggered after the backup.
Schedule::command('notifications:prune')->dailyAt('04:30')->withoutOverlapping();
