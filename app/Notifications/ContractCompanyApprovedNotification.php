<?php

namespace App\Notifications;

use App\Models\Contract;

class ContractCompanyApprovedNotification extends BaseNotification
{
    public Contract $contract;

    public function __construct(Contract $contract)
    {
        $this->contract = $contract;
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type' => 'contract_company_approved',
            'contract_id' => $this->contract->id,
            'title' => 'تم اعتماد العقد نهائياً',
            'message' => 'تم اعتماد العقد ' . $this->contract->title . ' من الطرفين. يمكنك الآن الدفع.',
            'title_en' => 'Contract fully approved',
            'message_en' => 'Contract ' . $this->contract->title . ' was approved by both parties. You can now pay.',
            'workspace_id' => $this->contract->workspace_id,
            'client_id' => $this->contract->workspace?->client_id,
        ];
    }

    public function toFcm($notifiable): array
    {
        return [
            'title' => 'اعتماد نهائي للعقد',
            'body' => 'تم اعتماد العقد ' . $this->contract->title . ' من الطرفين.',
            'title_en' => 'Contract final approval',
            'body_en' => 'Contract ' . $this->contract->title . ' was approved by both parties.',
            'data' => [
                'type' => 'contract.company_approved',
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