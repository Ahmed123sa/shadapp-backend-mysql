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
            'title_en' => $this->contract->title,
            'message_en' => "Reminder: contract {$this->contract->title} is waiting for your review",
            'workspace_id' => $this->contract->workspace_id,
            'client_id' => $this->contract->workspace?->client_id,
        ];
    }

    public function toFcm($notifiable): array
    {
        return [
            'title' => 'تذكير بعقد',
            'body' => "العقد {$this->contract->title} ينتظر مراجعتك",
            'title_en' => 'Contract reminder',
            'body_en' => "Contract {$this->contract->title} is waiting for your review",
            'data' => [
                'type' => 'contract',
                'id' => (string) $this->contract->id,
                'workspace_id' => (string) $this->contract->workspace_id,
                'client_id' => (string) ($this->contract->workspace?->client_id ?? ''),
            ],
        ];
    }
    public function toBroadcast($notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}