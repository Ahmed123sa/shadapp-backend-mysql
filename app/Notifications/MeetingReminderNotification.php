<?php

namespace App\Notifications;

use App\Models\Meeting;

class MeetingReminderNotification extends BaseNotification
{
    public Meeting $meeting;

    public function __construct(Meeting $meeting)
    {
        $this->meeting = $meeting;
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type' => 'meeting_reminder',
            'meeting_id' => $this->meeting->id,
            'title' => $this->meeting->title,
            'scheduled_at' => $this->meeting->scheduled_at,
            'message' => "تذكير باجتماع: {$this->meeting->title}",
        ];
    }

    public function toFcm($notifiable): array
    {
        return [
            'title' => 'تذكير باجتماع',
            'body' => "اجتماع {$this->meeting->title} في {$this->meeting->scheduled_at}",
            'data' => [
                'type' => 'meeting',
                'id' => (string) $this->meeting->id,
            ],
        ];
    }
    public function toBroadcast($notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}