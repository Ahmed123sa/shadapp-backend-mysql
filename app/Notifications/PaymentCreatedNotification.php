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

    public function toDatabase($notifiable): array
    {
        return [
            'type' => 'payment_created',
            'payment_id' => $this->payment->id,
            'amount' => $this->payment->amount,
            'message' => "ØªÙ… Ø¥Ù†Ø´Ø§Ø¡ Ø¯ÙØ¹Ø© Ø¬Ø¯ÙŠØ¯Ø©: {$this->payment->amount} Ø±.Ø³",
        ];
    }

    public function toFcm($notifiable): array
    {
        return [
            'title' => 'Ø¯ÙØ¹Ø© Ø¬Ø¯ÙŠØ¯Ø©',
            'body' => "ØªÙ… Ø¥Ù†Ø´Ø§Ø¡ Ø¯ÙØ¹Ø© Ø¨Ù‚ÙŠÙ…Ø© {$this->payment->amount} Ø±.Ø³",
            'data' => [
                'type' => 'payment',
                'id' => (string) $this->payment->id,
            ],
        ];
    }
    public function toBroadcast($notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}