<?php

namespace App\Notifications;

use App\Models\SubUser;

/**
 * A sub-user's own birthday greeting — push only.
 *
 * Not stored in `notifications`: a sub-user's bell (NotificationController)
 * lists their parent client's notifications, never their own, so a stored
 * row would never be shown or counted anywhere. See
 * plans/subuser-birthday-plan.md, level 2, for showing it in the bell.
 */
class SubUserBirthdayGreetingNotification extends BaseNotification
{
    public function __construct(
        public SubUser $subUser,
    ) {}

    public function via($notifiable): array
    {
        return [FcmChannel::class];
    }

    public function toFcm(object $notifiable): array
    {
        return [
            'title' => 'عيد ميلاد سعيد!',
            'body' => "كل سنة وأنت طيب يا {$this->subUser->name}!",
            'data' => [
                'type' => 'birthday_greeting',
                'workspace_id' => (string) ($this->subUser->client?->workspace?->id ?? ''),
            ],
        ];
    }
}
