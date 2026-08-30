<?php

namespace App\Notifications;

use App\Models\Payment;

class PaymentReviewedNotification extends BaseNotification
{
    public Payment $payment;
    public string $action;
    public bool $workspaceActivated;

    public function __construct(Payment $payment, string $action, bool $workspaceActivated = false)
    {
        $this->payment = $payment;
        $this->action = $action;
        $this->workspaceActivated = $workspaceActivated;
    }

    public function toDatabase($notifiable): array
    {
        if ($this->workspaceActivated) {
            return [
                'type' => 'workspace_activated',
                'workspace_id' => $this->payment->workspace_id,
                'client_id' => $this->payment->client_id,
                'message' => 'تم اعتماد الدفعة وتفعيل مساحة العمل — يمكنك الآن التواصل مع مدير الحساب',
            ];
        }
        $currency = $this->payment->currency ?? 'SAR';
        $label = $this->action === 'rejected' ? 'رفضها' : 'اعتمادها';
        return [
            'type' => 'payment_reviewed',
            'payment_id' => $this->payment->id,
            'action' => $this->action,
            'amount' => $this->payment->amount,
            'currency' => $currency,
            'workspace_id' => $this->payment->workspace_id,
            'client_id' => $this->payment->client_id,
            'message' => "الدفعة {$this->payment->amount} {$currency} تم {$label}",
        ];
    }

    public function toFcm($notifiable): array
    {
        if ($this->workspaceActivated) {
            return [
                'title' => 'تم تفعيل مساحة العمل',
                'body' => "تم اعتماد الدفعة وتفعيل مساحة العمل — يمكنك الآن التواصل مع مدير الحساب",
                'data' => [
                    'type' => 'payment.approved',
                    'workspace_id' => (string) $this->payment->workspace_id,
                    'client_id' => (string) $this->payment->client_id,
                ],
            ];
        }
        $currency = $this->payment->currency ?? 'SAR';
        $body = $this->action === 'rejected' ? "الدفعة {$this->payment->amount} {$currency} مرفوضة" : "الدفعة {$this->payment->amount} {$currency} مقبولة";
        return [
            'title' => 'مراجعة دفعة',
            'body' => $body,
            'data' => [
                'type' => 'payment.approved',
                'id' => (string) $this->payment->id,
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
