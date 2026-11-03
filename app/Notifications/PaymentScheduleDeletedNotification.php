<?php

namespace App\Notifications;

use App\Models\Payment;

class PaymentScheduleDeletedNotification extends BaseNotification
{
    public function __construct(public Payment $payment) {}

    public function toDatabase(object $notifiable): array
    {
        $body = 'تم مسح القسط ' . ($this->payment->installment_label ?? '') . ' — ' . number_format((float) $this->payment->amount, 2) . ' ' . ($this->payment->currency ?? 'SAR');
        $bodyEn = 'Installment ' . ($this->payment->installment_label ?? '') . ' was removed — ' . number_format((float) $this->payment->amount, 2) . ' ' . ($this->payment->currency ?? 'SAR');
        return [
            'type' => 'payment_schedule_deleted',
            'title_en' => 'Installment removed',
            'body_en' => $bodyEn,
            'message_en' => $bodyEn,
            'title' => 'تم مسح قسط',
            'body' => $body,
            'message' => $body,
            'payment_id' => $this->payment->id,
            'workspace_id' => $this->payment->workspace_id,
            'client_id' => $this->payment->client_id,
        ];
    }

    public function toFcm(object $notifiable): array
    {
        return [
            'title' => 'تم مسح قسط',
            'title_en' => 'Installment removed',
            'body_en' => 'Installment ' . ($this->payment->installment_label ?? '') . ' was removed — ' . number_format((float) $this->payment->amount, 2) . ' ' . ($this->payment->currency ?? 'SAR'),
            'body' => 'تم مسح القسط ' . ($this->payment->installment_label ?? '') . ' — ' . number_format((float) $this->payment->amount, 2) . ' ' . ($this->payment->currency ?? 'SAR'),
            'data' => [
                'type' => 'payment_schedule_deleted',
                'id' => (string) $this->payment->id,
                'workspace_id' => (string) $this->payment->workspace_id,
                'client_id' => (string) $this->payment->client_id,
            ],
        ];
    }

    public function toBroadcast(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
