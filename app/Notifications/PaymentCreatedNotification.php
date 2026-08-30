<?php

namespace App\Notifications;

use App\Models\Payment;

class PaymentCreatedNotification extends BaseNotification
{
    public Payment $payment;

    public function __construct(Payment $payment)
    {
        $this->payment = $payment;
    }

    private function clientName(): string
    {
        return $this->payment->workspace?->client?->company_name ?? 'العميل';
    }

    public function toDatabase($notifiable): array
    {
        $currency = $this->payment->currency ?? 'SAR';
        return [
            'type' => 'payment_created',
            'payment_id' => $this->payment->id,
            'amount' => $this->payment->amount,
            'currency' => $currency,
            'workspace_id' => $this->payment->workspace_id,
            'client_id' => $this->payment->client_id,
            'title' => 'دفعة جديدة',
            'message' => "أرسل العميل {$this->clientName()} دفعة بقيمة {$this->payment->amount} {$currency}",
        ];
    }

    public function toFcm($notifiable): array
    {
        $currency = $this->payment->currency ?? 'SAR';
        return [
            'title' => 'دفعة جديدة',
            'body' => "أرسل العميل {$this->clientName()} دفعة بقيمة {$this->payment->amount} {$currency}",
            'data' => [
                'type' => 'payment_created',
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