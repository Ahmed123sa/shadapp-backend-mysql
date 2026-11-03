<?php

namespace App\Notifications;

use App\Models\Payment;

class PaymentScheduledNotification extends BaseNotification
{
    public function __construct(public Payment $payment) {}

    public function toDatabase(object $notifiable): array
    {
        $currency = $this->payment->currency ?? 'SAR';
        $body = 'لديك دفعة مستحقة: ' . number_format((float) $this->payment->amount, 2) . ' ' . $currency . ' — ' . ($this->payment->installment_label ?? '');
        $bodyEn = 'You have a payment due: ' . number_format((float) $this->payment->amount, 2) . ' ' . $currency . ' — ' . ($this->payment->installment_label ?? '');
        return [
            'type' => 'payment_scheduled',
            'title_en' => 'New payment request',
            'body_en' => $bodyEn,
            'message_en' => $bodyEn,
            'title' => 'طلب دفعة جديد',
            'body' => $body,
            'message' => $body,
            'payment_id' => $this->payment->id,
            'workspace_id' => $this->payment->workspace_id,
            'client_id' => $this->payment->client_id,
            'due_date' => $this->payment->due_date?->toDateString(),
        ];
    }

    public function toFcm(object $notifiable): array
    {
        $currency = $this->payment->currency ?? 'SAR';
        return [
            'title' => 'طلب دفعة جديد',
            'title_en' => 'New payment request',
            'body_en' => 'You have a payment due: ' . number_format((float) $this->payment->amount, 2) . ' ' . $currency,
            'body' => 'لديك دفعة مستحقة: ' . number_format((float) $this->payment->amount, 2) . ' ' . $currency,
            'data' => [
                'type' => 'payment_scheduled',
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
