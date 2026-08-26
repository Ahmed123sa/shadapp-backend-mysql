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
                'message' => 'تم اعتماد الدفعة وتفعيل مساحة العمل — يمكنك الآن التواصل مع مدير الحساب',
            ];
        }
        return [
            'type' => 'payment_reviewed',
            'payment_id' => $this->payment->id,
            'action' => $this->action,
            'amount' => $this->payment->amount,
            'message' => "الدفعة {$this->payment->amount} ر.س تم اعتمادها",
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
                ],
            ];
        }
        return [
            'title' => 'مراجعة دفعة',
            'body' => "الدفعة {$this->payment->amount} ر.س مقبولة",
            'data' => [
                'type' => 'payment.approved',
                'id' => (string) $this->payment->id,
            ],
        ];
    }

    public function toBroadcast($notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
