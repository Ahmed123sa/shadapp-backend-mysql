<?php

namespace App\Notifications;

use App\Models\Contract;

class ContractReminderNotification extends BaseNotification
{
    public Contract $contract;

    public function __construct(Contract $contract)
    {
        $this->contract = $contract;
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type' => 'contract_reminder',
            'contract_id' => $this->contract->id,
            'title' => $this->contract->title,
            'message' => "تذكير: العقد {$this->contract->title} ينتظر مراجعتك",
        ];
    }

    public function toFcm($notifiable): array
    {
        return [
            'title' => 'تذكير بعقد',
            'body' => "العقد {$this->contract->title} ينتظر مراجعتك",
            'data' => [
                'type' => 'contract',
                'id' => (string) $this->contract->id,
            ],
        ];
    }
    public function toBroadcast($notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}