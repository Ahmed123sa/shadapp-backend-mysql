<?php

namespace App\Notifications;

use App\Models\Contract;

class ContractEditRequestedNotification extends BaseNotification
{
    public Contract $contract;

    public function __construct(Contract $contract)
    {
        $this->contract = $contract;
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type' => 'contract_edit_requested',
            'contract_id' => $this->contract->id,
            'title' => $this->contract->title,
            'message' => "طلب تعديل على العقد: {$this->contract->title}",
            'workspace_id' => $this->contract->workspace_id,
            'client_id' => $this->contract->workspace?->client_id,
        ];
    }

    public function toFcm($notifiable): array
    {
        return [
            'title' => 'طلب تعديل عقد',
            'body' => "العميل طلب تعديل العقد: {$this->contract->title}",
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
