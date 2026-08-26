<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

abstract class BaseNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function via($notifiable): array
    {
        $channels = ['database', FcmChannel::class];

        if (config('broadcasting.default') === 'reverb') {
            $channels[] = 'broadcast';
        }

        return $channels;
    }
}
