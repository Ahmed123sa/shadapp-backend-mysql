<?php

namespace App\Events;

use App\Models\Payment;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired from PaymentController@store (new pending payment) and @review
 * (approved/rejected). Distinct from PaymentScheduleChanged, which only
 * covers the manager-initiated installment schedule, not a payment's own
 * status.
 */
class PaymentStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public Payment $payment) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('workspace.' . $this->payment->workspace_id)];
    }

    public function broadcastAs(): string
    {
        return 'payment.status_changed';
    }

    public function broadcastWith(): array
    {
        return [
            'payment_id' => $this->payment->id,
            'status' => $this->payment->status,
            'amount' => $this->payment->amount,
            'currency' => $this->payment->currency,
        ];
    }
}
