<?php

namespace App\Notifications;

use App\Models\Payment;

/**
 * Tells the company side (the other accountants and the client's manager)
 * that a payment proof was approved or rejected, so their pending lists and
 * badges refresh. The client gets PaymentReviewedNotification instead — this
 * one names the client and never goes to them.
 */
class PaymentReviewedStaffNotification extends BaseNotification
{
    public Payment $payment;
    public string $action;

    public function __construct(Payment $payment, string $action)
    {
        $this->payment = $payment;
        $this->action = $action;
    }

    private function text(): array
    {
        $currency = $this->payment->currency ?? 'SAR';
        $client = $this->payment->workspace?->client?->company_name ?? 'العميل';
        $verb = $this->action === 'rejected' ? 'رفض' : 'اعتماد';

        return [
            $this->action === 'rejected' ? 'تم رفض دفعة' : 'تم اعتماد دفعة',
            "تم {$verb} دفعة {$client} بقيمة {$this->payment->amount} {$currency}",
        ];
    }    private function textEn(): array
    {
        $currency = $this->payment->currency ?? 'SAR';
        $client = $this->payment->workspace?->client?->company_name ?? 'The client';
        $verb = $this->action === 'rejected' ? 'rejected' : 'approved';

        return [
            $this->action === 'rejected' ? 'Payment rejected' : 'Payment approved',
            "{$client}'s payment of {$this->payment->amount} {$currency} was {$verb}",
        ];
    }

    public function toDatabase($notifiable): array
    {
        [$title, $message] = $this->text();
        [$titleEn, $messageEn] = $this->textEn();
        return [
            'type' => 'payment_reviewed_staff',
            'title' => $title,
            'message' => $message,
            'title_en' => $titleEn,
            'message_en' => $messageEn,
            'payment_id' => $this->payment->id,
            'action' => $this->action,
            'amount' => $this->payment->amount,
            'currency' => $this->payment->currency ?? 'SAR',
            'workspace_id' => $this->payment->workspace_id,
            'client_id' => $this->payment->client_id,
        ];
    }

    public function toFcm($notifiable): array
    {
        [$title, $message] = $this->text();
        [$titleEn, $messageEn] = $this->textEn();
        return [
            'title' => $title,
            'body' => $message,
            'title_en' => $titleEn,
            'body_en' => $messageEn,
            'data' => [
                'type' => 'payment_reviewed_staff',
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
