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
        $messages = [
            '3_days' => 'تذكير: دفعة مستحقة خلال 3 أيام',
            'today' => 'تذكير: دفعة مستحقة اليوم',
            'overdue' => 'تنبيه: دفعة متأخرة عن موعد الاستحقاق',
        ];

        return [
            'type' => 'payment_reminder',
            'title' => $messages[$this->reminderType] ?? 'تذكير بالدفع',
            'body' => number_format((float) $this->payment->amount, 2) . ' ريال — ' . ($this->payment->installment_label ?? ''),
            'payment_id' => $this->payment->id,
            'workspace_id' => $this->payment->workspace_id,
            'reminder_type' => $this->reminderType,
            'due_date' => $this->payment->due_date?->toDateString(),
        ];
    }

    public function toFcm(object $notifiable): array
    {
        $messages = [
            '3_days' => 'تذكير: دفعة مستحقة خلال 3 أيام',
            'today' => 'تذكير: دفعة مستحقة اليوم',
            'overdue' => 'تنبيه: دفعة متأخرة',
        ];

        return [
            'title' => $messages[$this->reminderType] ?? 'تذكير بالدفع',
            'body' => number_format((float) $this->payment->amount, 2) . ' ريال',
            'data' => [
                'type' => 'payment_reminder',
                'id' => (string) $this->payment->id,
            ],
        ];
    }

    public function toBroadcast(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
