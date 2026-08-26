<?php

namespace App\Notifications;

use App\Models\ChatMessage;

class ChatMessageSentNotification extends BaseNotification
{
    public ChatMessage $message;

    public function __construct(ChatMessage $message)
    {
        $this->message = $message;
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
