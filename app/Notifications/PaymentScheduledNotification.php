<?php

namespace App\Notifications;

use App\Models\Payment;

class PaymentScheduledNotification extends BaseNotification
{
    public function __construct(public Payment $payment) {}

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'payment_scheduled',
            'title' => 'طلب دفعة جديد',
            'body' => 'لديك دفعة مستحقة: ' . number_format((float) $this->payment->amount, 2) . ' ريال — ' . ($this->payment->installment_label ?? ''),
            'payment_id' => $this->payment->id,
            'workspace_id' => $this->payment->workspace_id,
            'due_date' => $this->payment->due_date?->toDateString(),
        ];
    }

    public function toFcm(object $notifiable): array
    {
        return [
            'title' => 'طلب دفعة جديد',
            'body' => 'لديك دفعة مستحقة: ' . number_format((float) $this->payment->amount, 2) . ' ريال',
            'data' => [
                'type' => 'payment_scheduled',
                'id' => (string) $this->payment->id,
            ],
        ];
    }

    public function toBroadcast(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
