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
            'message' => "كل سنة وأنت طيب! نتمنى لك عاماً موفقاً",
            'title_en' => 'Happy birthday!',
            'body_en' => 'Happy birthday! Wishing you a wonderful year',
            'message_en' => 'Happy birthday! Wishing you a wonderful year',
            'client_id' => $this->client->id,
            // plans/notifications-badges-toasts-plan.md ن3 — same reasoning
            // as BirthdayReminderNotification.
            'workspace_id' => $this->client->workspace?->id,
        ];
    }

    public function toFcm(object $notifiable): array
    {
        return [
            'title' => 'عيد ميلاد سعيد!',
            'body' => "كل سنة وأنت طيب!",
            'title_en' => 'Happy birthday!',
            'body_en' => 'Happy birthday!',
            'data' => [
                'type' => 'birthday_greeting',
                'client_id' => (string) $this->client->id,
                'workspace_id' => (string) ($this->client->workspace?->id ?? ''),
            ],
        ];
    }

    public function toBroadcast(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
