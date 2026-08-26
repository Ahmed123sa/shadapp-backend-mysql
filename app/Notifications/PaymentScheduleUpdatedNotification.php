<?php

namespace App\Notifications;

use App\Models\Payment;

class PaymentScheduleUpdatedNotification extends BaseNotification
{
    public function __construct(public Payment $payment) {}

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'payment_schedule_updated',
            'title' => 'تم تعديل قسط',
            'body' => 'تم تعديل القسط ' . ($this->payment->installment_label ?? '') . ' — المبلغ الجديد: ' . number_format((float) $this->payment->amount, 2) . ' ' . ($this->payment->currency ?? 'SAR'),
            'payment_id' => $this->payment->id,
            'workspace_id' => $this->payment->workspace_id,
            'due_date' => $this->payment->due_date?->toDateString(),
        ];
    }

    public function toFcm(object $notifiable): array
    {
        return [
            'title' => 'تم تعديل قسط',
            'body' => 'تم تعديل القسط ' . ($this->payment->installment_label ?? '') . ' — المبلغ الجديد: ' . number_format((float) $this->payment->amount, 2) . ' ' . ($this->payment->currency ?? 'SAR'),
            'data' => [
                'type' => 'payment_schedule_updated',
                'id' => (string) $this->payment->id,
            ],
        ];
    }

    public function toBroadcast(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
