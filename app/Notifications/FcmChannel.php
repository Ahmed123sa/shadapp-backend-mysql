<?php

namespace App\Notifications;

use App\Models\Client;
use App\Models\MobileNotificationToken;
use App\Models\SubUser;
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

        // plans/notifications-badges-toasts-plan.md ن14/ح2ب — a sub-user got
        // no push at all before this, even for a notification type it's
        // fully permitted to see in GET /notifications. Only Client-directed
        // notifications are ever read through a sub-user (see
        // NotificationController::index), so that's the only case gated
        // here. The type check reuses toDatabase()['type'] rather than
        // toFcm()'s data.type, because the latter uses inconsistent naming
        // across notification classes (ن4) while toDatabase()'s 'type' is
        // exactly what SubUser::canSeeNotificationType() and
        // NotificationController's own filtering are keyed on.
        if ($notifiable instanceof Client) {
            $type = method_exists($notification, 'toDatabase')
                ? ($notification->toDatabase($notifiable)['type'] ?? null)
                : null;

            $eligibleSubUserIds = $notifiable->subUsers()
                ->get()
                ->filter(fn (SubUser $subUser) => $subUser->canSeeNotificationType($type))
                ->pluck('id');

            if ($eligibleSubUserIds->isNotEmpty()) {
                $deviceTokens = $deviceTokens->merge(
                    MobileNotificationToken::whereIn('tokenable_id', $eligibleSubUserIds)
                        ->where('tokenable_type', SubUser::class)
                        ->get()
                );
            }
        }

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
