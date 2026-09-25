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
            // plans/notifications-badges-toasts-plan.md ن3 — a manager
            // viewing GET /notifications never saw this: the filter there
            // only kept notifications resolvable to one of their managed
            // workspaces via workspace_id/contract_id/payment_id/
            // approval_id, and this had none of those.
            'workspace_id' => $this->meeting->workspace_id,
            'client_id' => $this->meeting->workspace?->client_id,
            'title' => $this->meeting->title,
            'scheduled_at' => $this->meeting->scheduled_at,
            'message' => "تذكير باجتماع: {$this->meeting->title}",
        ];
    }

    public function toFcm($notifiable): array
    {
        return [
            'title' => 'تذكير باجتماع',
            // Plain text can't be converted per reader like the apps do, so
            // it uses the business timezone (App\Support\DisplayTime).
            'body' => "اجتماع {$this->meeting->title} في " . \App\Support\DisplayTime::format($this->meeting->scheduled_at),
            'data' => [
                'type' => 'meeting',
                'id' => (string) $this->meeting->id,
                'workspace_id' => (string) $this->meeting->workspace_id,
                'client_id' => (string) ($this->meeting->workspace?->client_id ?? ''),
            ],
        ];
    }
    public function toBroadcast($notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}