<?php

namespace App\Notifications;

use App\Models\Contract;

class ContractClientApprovedNotification extends BaseNotification
{
    public Contract $contract;

    public function __construct(Contract $contract)
    {
        $this->contract = $contract;
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type' => 'contract_client_approved',
            'contract_id' => $this->contract->id,
            'title' => 'اعتماد عقد من العميل',
            'message' => 'قام العميل ' . ($this->contract->workspace->client->company_name ?? '') . ' باعتماد العقد: ' . $this->contract->title,
            'workspace_id' => $this->contract->workspace_id,
        ];
    }

    public function toFcm($notifiable): array
    {
        return [
            'title' => 'اعتماد عقد من العميل',
            'body' => 'قام العميل ' . ($this->contract->workspace->client->company_name ?? '') . ' باعتماد العقد: ' . $this->contract->title,
            'data' => ['type' => 'contract', 'id' => (string) $this->contract->id],
        ];
    }
    public function toBroadcast($notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}