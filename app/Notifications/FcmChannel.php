<?php

namespace App\Notifications;

use App\Models\MobileNotificationToken;
use App\Services\FirebaseService;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class FcmChannel
{
    public function send($notifiable, Notification $notification): void
    {
        $data = $notification->toFcm($notifiable);

        $deviceTokens = MobileNotificationToken::where('tokenable_id', $notifiable->id)
            ->where('tokenable_type', get_class($notifiable))
            ->get();

        if ($deviceTokens->isEmpty()) {
            return;
        }

        $firebase = app(FirebaseService::class);

        $notifData = [
            'title' => mb_convert_encoding($data['title'] ?? '', 'UTF-8'),
            'body' => mb_convert_encoding($data['body'] ?? '', 'UTF-8'),
        ];

        $customData = $data['data'] ?? [];

        foreach ($deviceTokens as $deviceToken) {
            try {
                $result = $firebase->sendMessage($deviceToken->token, $notifData, $customData);
                if ($result === 'unregistered') {
                    $deviceToken->delete();
                    Log::info('Removed unregistered FCM token for tokenable_id ' . $notifiable->id);
                }
            } catch (\Exception $e) {
                Log::warning('FCM send failed: ' . $e->getMessage());
            }
        }
    }
}
