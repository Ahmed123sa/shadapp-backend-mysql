<?php

namespace App\Notifications;

use App\Models\Payment;

class PaymentReminderNotification extends BaseNotification
{
    public function __construct(
        public Payment $payment,
        public string $reminderType,
    ) {}

    public function toDatabase(object $notifiable): array
    {
        $currency = $this->payment->currency ?? 'SAR';
        $messages = [
            '3_days' => 'تذكير: دفعة مستحقة خلال 3 أيام',
            'today' => 'تذكير: دفعة مستحقة اليوم',
            'overdue' => 'تنبيه: دفعة متأخرة عن موعد الاستحقاق',
        ];
        $titlesEn = [
            '3_days' => 'Reminder: a payment is due in 3 days',
            'today' => 'Reminder: a payment is due today',
            'overdue' => 'Alert: a payment is past its due date',
        ];
        $body = number_format((float) $this->payment->amount, 2) . ' ' . $currency . ' — ' . ($this->payment->installment_label ?? '');

        return [
            'type' => 'payment_reminder',
            'title' => $messages[$this->reminderType] ?? 'تذكير بالدفع',
            'title_en' => $titlesEn[$this->reminderType] ?? 'Payment reminder',
            'body_en' => $body,
            'message_en' => $body,
            'body' => $body,
            'message' => $body,
            'payment_id' => $this->payment->id,
            'workspace_id' => $this->payment->workspace_id,
            'client_id' => $this->payment->client_id,
            'reminder_type' => $this->reminderType,
            'due_date' => $this->payment->due_date?->toDateString(),
        ];
    }

    public function toFcm(object $notifiable): array
    {
        $currency = $this->payment->currency ?? 'SAR';
        $messages = [
            '3_days' => 'تذكير: دفعة مستحقة خلال 3 أيام',
            'today' => 'تذكير: دفعة مستحقة اليوم',
            'overdue' => 'تنبيه: دفعة متأخرة',
        ];

        return [
            'title' => $messages[$this->reminderType] ?? 'تذكير بالدفع',
            'title_en' => [
                '3_days' => 'Reminder: a payment is due in 3 days',
                'today' => 'Reminder: a payment is due today',
                'overdue' => 'Alert: a payment is overdue',
            ][$this->reminderType] ?? 'Payment reminder',
            'body_en' => number_format((float) $this->payment->amount, 2) . ' ' . $currency,
            'body' => number_format((float) $this->payment->amount, 2) . ' ' . $currency,
            'data' => [
                'type' => 'payment_reminder',
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
