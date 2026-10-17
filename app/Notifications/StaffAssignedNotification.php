<?php

namespace App\Notifications;

use App\Models\Workspace;

/**
 * Tells a company staff member they were added to a client's team
 * (STAFF_TEAMS_PLAN.md §٤). Being removed sends nothing.
 */
class StaffAssignedNotification extends BaseNotification
{
    public Workspace $workspace;

    public function __construct(Workspace $workspace)
    {
        $this->workspace = $workspace;
    }

    private function clientName(): string
    {
        $client = $this->workspace->client;

        return (string) ($client?->company_name ?: $client?->contact_person ?: 'عميل');
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type' => 'staff_assigned',
            'workspace_id' => $this->workspace->id,
            'client_id' => $this->workspace->client_id,
            'title' => 'تم إضافتك لعميل جديد',
            'message' => "تم إضافتك لفريق العميل {$this->clientName()}",
        ];
    }

    public function toFcm($notifiable): array
    {
        return [
            'title' => 'تم إضافتك لعميل جديد',
            'body' => "تم إضافتك لفريق العميل {$this->clientName()}",
            'data' => [
                'type' => 'staff_assigned',
                'workspace_id' => (string) $this->workspace->id,
                'client_id' => (string) $this->workspace->client_id,
            ],
        ];
    }

    public function toBroadcast($notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
