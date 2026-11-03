<?php

namespace App\Notifications;

use App\Models\Approval;

class ApprovalRespondedNotification extends BaseNotification
{
    public Approval $approval;

    public function __construct(Approval $approval)
    {
        $this->approval = $approval;
    }

    public function toDatabase($notifiable): array
    {
        $status = $this->approval->status === 'approved' ? 'تم الموافقة' : 'طلب تعديل';
        $statusEn = $this->approval->status === 'approved' ? 'Approved' : 'Edit requested';
        return [
            'type' => 'approval_responded',
            'approval_id' => $this->approval->id,
            // So tapping the notification opens this workspace's Approvals
            // tab (the apps route on workspace_id; without it they fell back
            // to the dashboard and the tap seemed to do nothing).
            'workspace_id' => $this->approval->workspace_id,
            'client_id' => $this->approval->workspace?->client_id,
            'title' => $this->approval->title,
            'message' => "حالة طلب الموافقة '{$this->approval->title}': {$status}",
            'title_en' => $this->approval->title,
            'message_en' => "Approval request '{$this->approval->title}': {$statusEn}",
        ];
    }

    public function toFcm($notifiable): array
    {
        $status = $this->approval->status === 'approved' ? 'مقبولة' : 'طلب تعديل';
        $statusEn = $this->approval->status === 'approved' ? 'approved' : 'edit requested';
        return [
            'title' => 'رد على طلب موافقة',
            'body' => "طلب الموافقة '{$this->approval->title}' أصبح {$status}",
            'title_en' => 'Approval request response',
            'body_en' => "Approval request '{$this->approval->title}' is now {$statusEn}",
            'data' => [
                'type' => 'approval',
                'id' => (string) $this->approval->id,
                'workspace_id' => (string) $this->approval->workspace_id,
                'client_id' => (string) ($this->approval->workspace?->client_id ?? ''),
            ],
        ];
    }

    public function toBroadcast($notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
