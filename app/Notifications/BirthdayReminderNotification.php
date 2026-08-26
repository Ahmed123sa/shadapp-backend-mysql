<?php

namespace App\Notifications;

use App\Models\Client;

class BirthdayReminderNotification extends BaseNotification
{
    public function __construct(
        public Client $client,
    ) {}

    public function via($notifiable): array
    {
        return array_diff(parent::via($notifiable), [\Illuminate\Contracts\Queue\ShouldQueue::class]);
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'birthday_reminder',
            'title' => 'تذكير: عيد ميلاد العميل',
            'body' => "غداً عيد ميلاد العميل {$this->client->contact_person}",
            'client_id' => $this->client->id,
        ];
    }

    public function toFcm(object $notifiable): array
    {
        return [
            'title' => 'تذكير: عيد ميلاد العميل',
            'body' => "غداً عيد ميلاد {$this->client->contact_person}",
            'data' => [
                'type' => 'birthday_reminder',
                'client_id' => (string) $this->client->id,
            ],
        ];
    }

    public function toBroadcast(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
