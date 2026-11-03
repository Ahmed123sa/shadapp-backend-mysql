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
                'title' => 'تم تفعيل مساحة العمل',
                'workspace_id' => $this->payment->workspace_id,
                'client_id' => $this->payment->client_id,
                'message' => 'تم اعتماد الدفعة وتفعيل مساحة العمل — يمكنك الآن التواصل مع مدير الحساب',
                'title_en' => 'Workspace activated',
                'message_en' => 'The payment was approved and your workspace is active. You can now contact your account manager.',
            ];
        }
        $currency = $this->payment->currency ?? 'SAR';
        $label = $this->action === 'rejected' ? 'رفضها' : 'اعتمادها';
        $data = [
            'type' => 'payment_reviewed',
            'title' => $this->action === 'rejected' ? 'تم رفض الدفعة' : 'تم اعتماد الدفعة',
            'payment_id' => $this->payment->id,
            'action' => $this->action,
            'amount' => $this->payment->amount,
            'currency' => $currency,
            'workspace_id' => $this->payment->workspace_id,
            'client_id' => $this->payment->client_id,
            'message' => "الدفعة {$this->payment->amount} {$currency} تم {$label}",
            'title_en' => $this->action === 'rejected' ? 'Payment rejected' : 'Payment approved',
            'message_en' => "Payment {$this->payment->amount} {$currency} was " . ($this->action === 'rejected' ? 'rejected' : 'approved'),
        ];
        if ($this->action === 'rejected' && !empty($this->payment->rejection_reason)) {
            $data['rejection_reason'] = $this->payment->rejection_reason;
        }
        return $data;
    }

    public function toFcm($notifiable): array
    {
        if ($this->workspaceActivated) {
            return [
                'title' => 'تم تفعيل مساحة العمل',
                'body' => "تم اعتماد الدفعة وتفعيل مساحة العمل — يمكنك الآن التواصل مع مدير الحساب",
                'title_en' => 'Workspace activated',
                'body_en' => 'The payment was approved and your workspace is active. You can now contact your account manager.',
                'data' => [
                    'type' => 'payment.approved',
                    'workspace_id' => (string) $this->payment->workspace_id,
                    'client_id' => (string) $this->payment->client_id,
                ],
            ];
        }
        $currency = $this->payment->currency ?? 'SAR';
        $body = $this->action === 'rejected' ? "الدفعة {$this->payment->amount} {$currency} مرفوضة" : "الدفعة {$this->payment->amount} {$currency} مقبولة";
        $type = $this->action === 'rejected' ? 'payment.rejected' : 'payment.approved';
        return [
            'title' => 'مراجعة دفعة',
            'body' => $body,
            'title_en' => 'Payment review',
            'body_en' => "Payment {$this->payment->amount} {$currency} " . ($this->action === 'rejected' ? 'rejected' : 'approved'),
            'data' => [
                'type' => $type,
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
