<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Notifications\ContractReminderNotification;
use Illuminate\Console\Command;

class SendContractReminders extends Command
{
    protected $signature = 'contracts:send-reminders';
    protected $description = 'Send reminders for contracts pending review';

    public function handle(): void
    {
        $contracts = Contract::whereIn('status', ['sent', 'edit_requested'])
            ->where('updated_at', '<=', now()->subDays(3))
            ->get();

        $sent = 0;

        foreach ($contracts as $contract) {
            $manager = $contract->workspace?->manager;
            if ($manager) {
                $manager->notify(new ContractReminderNotification($contract));
                $sent++;
            }
        }

        $this->info("Sent {$sent} contract reminder(s).");
    }
}
