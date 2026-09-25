<?php

namespace App\Notifications;

use App\Models\ChatMessage;

class ChatMessageSentNotification extends BaseNotification
{
    public ChatMessage $message;

    /**
     * plans/notifications-badges-toasts-plan.md ن16 (§3 س1) — every chat
     * message used to trigger its own push, so a 30-message conversation
     * meant 30 pushes. $skipPush is set by ChatController::store() when the
     * recipient already has an unread chat notification for this workspace
     * from the last 5 minutes: the database row and the realtime broadcast
     * still go out every message (chat itself and the badge count must stay
     * accurate), only the FCM push is throttled to once per 5-minute window
     * per conversation. Reading the chat (markAsRead) clears the unread chat
     * notification, so the very next message after that gets pushed again.
     */
    public bool $skipPush;

    public function __construct(ChatMessage $message, bool $skipPush = false)
    {
        $this->message = $message;
        $this->skipPush = $skipPush;
    }

    public function via($notifiable): array
    {
        $channels = parent::via($notifiable);
        if ($this->skipPush) {
            $channels = array_values(array_diff($channels, [FcmChannel::class]));
        }
        return $channels;
    }

    public function toDatabase($notifiable): array
    {
        $senderName = $this->message->sender?->name ?? 'مستخدم';
        return [
            'type' => 'chat',
            'workspace_id' => $this->message->workspace_id,
            'client_id' => $this->message->workspace->client_id,
            'sender_name' => $senderName,
            'text' => $this->message->message ?? '',
            'title' => $senderName,
            'message' => "رسالة جديدة من {$senderName}",
        ];
    }

    public function toFcm($notifiable): array
    {
        $senderName = $this->message->sender?->name ?? 'مستخدم';
        return [
            'title' => 'رسالة جديدة',
            'body' => "{$senderName}: {$this->message->message}",
            'data' => [
                'type' => 'chat',
                'workspace_id' => (string) $this->message->workspace_id,
                'client_id' => (string) $this->message->workspace->client_id,
                'sender_name' => $senderName,
            ],
        ];
    }

    public function toBroadcast($notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
