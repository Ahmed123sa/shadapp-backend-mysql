<?php

namespace App\Notifications;

use App\Models\Contract;

class ContractClientSignedNotification extends BaseNotification
{
    public Contract $contract;

    public function __construct(Contract $contract)
    {
        $this->contract = $contract;
    }

    public function toDatabase($notifiable): array
    {
        $workspace = $this->contract->workspace;
        return [
            'type' => 'contract_client_signed',
            'contract_id' => $this->contract->id,
            'workspace_id' => $workspace->id,
            'client_id' => $workspace->client_id,
            'title' => 'توقيع إلكتروني',
            'message' => 'قام العميل ' . ($workspace->client->company_name ?? '') . ' بتوقيع العقد إلكترونيًا: ' . $this->contract->title,
        ];
    }

    public function toFcm($notifiable): array
    {
        $workspace = $this->contract->workspace;
        return [
            'title' => 'توقيع إلكتروني',
            'body' => 'قام العميل ' . ($workspace->client->company_name ?? '') . ' بتوقيع العقد إلكترونيًا: ' . $this->contract->title,
            'data' => [
                'type' => 'contract_client_signed',
                'contract_id' => (string) $this->contract->id,
                'workspace_id' => (string) $workspace->id,
                'client_id' => (string) $workspace->client_id,
            ],
        ];
    }

    public function toBroadcast($notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
