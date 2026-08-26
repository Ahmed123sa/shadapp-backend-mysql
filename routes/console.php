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
