<?php

namespace App\Notifications;

use App\Models\Payment;

class PaymentScheduleDeletedNotification extends BaseNotification
{
    public function __construct(public Payment $payment) {}

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'payment_schedule_deleted',
            'title' => 'تم مسح قسط',
            'body' => 'تم مسح القسط ' . ($this->payment->installment_label ?? '') . ' — ' . number_format((float) $this->payment->amount, 2) . ' ' . ($this->payment->currency ?? 'SAR'),
            'payment_id' => $this->payment->id,
            'workspace_id' => $this->payment->workspace_id,
        ];
    }

    public function toFcm(object $notifiable): array
    {
        return [
            'title' => 'تم مسح قسط',
            'body' => 'تم مسح القسط ' . ($this->payment->installment_label ?? '') . ' — ' . number_format((float) $this->payment->amount, 2) . ' ' . ($this->payment->currency ?? 'SAR'),
            'data' => [
                'type' => 'payment_schedule_deleted',
                'id' => (string) $this->payment->id,
            ],
        ];
    }

    public function toBroadcast(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
