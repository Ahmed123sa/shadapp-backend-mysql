<?php

namespace App\Notifications;

use App\Models\Client;

class ClientSignatureSavedNotification extends BaseNotification
{
    public Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public function toDatabase($notifiable): array
    {
        $workspace = $this->client->workspace;
        return [
            'type' => 'client_signature_saved',
            'client_id' => $this->client->id,
            'workspace_id' => $workspace?->id,
            'title' => 'اعتماد التوقيع الإلكتروني',
            'message' => 'قام العميل ' . ($this->client->company_name ?? $this->client->contact_person ?? '') . ' باعتماد وتحديث توقيعه الإلكتروني.',
        ];
    }

    public function toFcm($notifiable): array
    {
        $workspace = $this->client->workspace;
        return [
            'title' => 'اعتماد التوقيع الإلكتروني',
            'body' => 'قام العميل ' . ($this->client->company_name ?? $this->client->contact_person ?? '') . ' باعتماد وتحديث توقيعه الإلكتروني.',
            'data' => [
                'type' => 'client_signature_saved',
                'client_id' => (string) $this->client->id,
                'workspace_id' => $workspace ? (string) $workspace->id : '',
            ],
        ];
    }

    public function toBroadcast($notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
