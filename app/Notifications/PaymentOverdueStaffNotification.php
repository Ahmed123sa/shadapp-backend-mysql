<?php

namespace App\Notifications;

use App\Models\Payment;

/**
 * Tells the accountants that a scheduled installment has just passed its due
 * date without the client paying. Sent once, at the moment the payment turns
 * "overdue". The client gets PaymentReminderNotification instead.
 */
class PaymentOverdueStaffNotification extends BaseNotification
{
    public Payment $payment;

    public function __construct(Payment $payment)
    {
        $this->payment = $payment;
    }

    private function text(): array
    {
        $currency = $this->payment->currency ?? 'SAR';
        $client = $this->payment->workspace?->client?->company_name ?? 'العميل';

        return [
            'دفعة متأخرة',
            "دفعة {$client} بقيمة {$this->payment->amount} {$currency} عدّت موعد الاستحقاق",
        ];
    }

    public function toDatabase($notifiable): array
    {
        [$title, $message] = $this->text();

        return [
            'type' => 'payment_overdue_staff',
            'title' => $title,
            'message' => $message,
            'payment_id' => $this->payment->id,
            'amount' => $this->payment->amount,
            'currency' => $this->payment->currency ?? 'SAR',
            'workspace_id' => $this->payment->workspace_id,
            'client_id' => $this->payment->client_id,
        ];
    }

    public function toFcm($notifiable): array
    {
        [$title, $message] = $this->text();

        return [
            'title' => $title,
            'body' => $message,
            'data' => [
                'type' => 'payment_overdue_staff',
                'id' => (string) $this->payment->id,
                'payment_id' => (string) $this->payment->id,
                'workspace_id' => (string) $this->payment->workspace_id,
                'client_id' => (string) $this->payment->client_id,
            ],
        ];
    }

    public function toBroadcast($notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
