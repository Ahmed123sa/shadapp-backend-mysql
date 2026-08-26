<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Notifications\BirthdayReminderNotification;
use App\Notifications\BirthdayGreetingNotification;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendBirthdayReminders extends Command
{
    protected $signature = 'birthdays:send-reminders';
    protected $description = 'Send birthday reminders to managers and greetings to clients';

    public function handle(): void
    {
        $now = Carbon::now();
        $sent = 0;

        // Manager reminders: tomorrow's birthday
        $tomorrowClients = Client::where('status', 'active')
            ->whereNotNull('date_of_birth')
            ->whereMonth('date_of_birth', $now->copy()->addDay()->month)
            ->whereDay('date_of_birth', $now->copy()->addDay()->day)
            ->get();

        foreach ($tomorrowClients as $client) {
            if ($client->manager) {
                $client->manager->notify(new BirthdayReminderNotification($client));
                $sent++;
            }
        }

        // Client greetings: today's birthday
        $todayClients = Client::where('status', 'active')
            ->whereNotNull('date_of_birth')
            ->whereMonth('date_of_birth', $now->month)
            ->whereDay('date_of_birth', $now->day)
            ->get();

        foreach ($todayClients as $client) {
            $client->notify(new BirthdayGreetingNotification($client));
            $sent++;
        }

        $this->info("Sent {$sent} birthday notification(s).");
    }
}
