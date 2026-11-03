<?php

namespace App\Notifications;

use App\Models\Contract;

class ContractCompletedNotification extends BaseNotification
{
    public Contract $contract;

    public function __construct(Contract $contract)
    {
        $this->contract = $contract;
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type' => 'contract_completed',
            'contract_id' => $this->contract->id,
            'title' => $this->contract->title,
            'message' => "تم اكتمال العقد: {$this->contract->title}",
            'title_en' => $this->contract->title,
            'message_en' => "Contract completed: {$this->contract->title}",
            'workspace_id' => $this->contract->workspace_id,
            'client_id' => $this->contract->workspace?->client_id,
        ];
    }

    public function toFcm($notifiable): array
    {
        return [
            'title' => 'اكتمال عقد',
            'body' => "تم اكتمال العقد {$this->contract->title}",
            'title_en' => 'Contract completed',
            'body_en' => "Contract {$this->contract->title} is complete",
            'data' => [
                'type' => 'contract.completed',
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