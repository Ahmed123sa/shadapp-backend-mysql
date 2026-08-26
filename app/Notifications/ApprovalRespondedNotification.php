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
        return [
            'type' => 'approval_responded',
            'approval_id' => $this->approval->id,
            'title' => $this->approval->title,
            'message' => "حالة طلب الموافقة '{$this->approval->title}': {$status}",
        ];
    }

    public function toFcm($notifiable): array
    {
        $status = $this->approval->status === 'approved' ? 'مقبولة' : 'طلب تعديل';
        return [
            'title' => 'رد على طلب موافقة',
            'body' => "طلب الموافقة '{$this->approval->title}' أصبح {$status}",
            'data' => [
                'type' => 'approval',
                'id' => (string) $this->approval->id,
            ],
        ];
    }

    public function toBroadcast($notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
