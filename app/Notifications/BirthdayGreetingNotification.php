<?php

namespace App\Notifications;

use App\Models\Client;

class BirthdayGreetingNotification extends BaseNotification
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
            'type' => 'birthday_greeting',
            'title' => 'عيد ميلاد سعيد!',
            'body' => "كل سنة وأنت طيب! نتمنى لك عاماً موفقاً",
            'client_id' => $this->client->id,
        ];
    }

    public function toFcm(object $notifiable): array
    {
        return [
            'title' => 'عيد ميلاد سعيد!',
            'body' => "كل سنة وأنت طيب!",
            'data' => [
                'type' => 'birthday_greeting',
                'client_id' => (string) $this->client->id,
            ],
        ];
    }

    public function toBroadcast(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
