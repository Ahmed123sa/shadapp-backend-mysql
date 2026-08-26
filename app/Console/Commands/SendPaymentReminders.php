<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Notifications\PaymentReminderNotification;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendPaymentReminders extends Command
{
    protected $signature = 'payments:send-reminders';
    protected $description = 'Send payment due-date reminders and mark overdue payments';

    public function handle(): void
    {
        $now = Carbon::now();
        $sent = 0;

        $upcoming = Payment::where('status', 'scheduled')
            ->whereDate('due_date', $now->copy()->addDays(3)->toDateString())
            ->get();
        foreach ($upcoming as $payment) {
            $payment->client->notify(new PaymentReminderNotification($payment, '3_days'));
            $sent++;
        }

        $dueToday = Payment::where('status', 'scheduled')
            ->whereDate('due_date', $now->toDateString())
            ->get();
        foreach ($dueToday as $payment) {
            $payment->client->notify(new PaymentReminderNotification($payment, 'today'));
            $sent++;
        }

        $overdue = Payment::where('status', 'scheduled')
            ->whereDate('due_date', '<', $now->toDateString())
            ->get();
        foreach ($overdue as $payment) {
            $payment->update(['status' => 'overdue']);
            $payment->client->notify(new PaymentReminderNotification($payment, 'overdue'));
            $sent++;
        }

        $this->info("Sent {$sent} payment reminder(s).");
    }
}
