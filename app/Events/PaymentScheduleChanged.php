<?php

namespace App\Events;

use App\Models\Payment;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class PaymentScheduleChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public Payment $payment, public string $action) {}

    public function broadcastOn(): array
    {
        // See App\Domains\Chat\MessageSent::broadcastOn() — must match the
        // private channel name that routes/channels.php authorizes.
        return [new PrivateChannel('workspace.' . $this->payment->workspace_id)];
    }

    public function broadcastAs(): string
    {
        return 'payment.schedule.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'payment_id' => $this->payment->id,
            'action' => $this->action,
            'amount' => $this->payment->amount,
            'currency' => $this->payment->currency,
            'due_date' => $this->payment->due_date?->toDateString(),
            'installment_label' => $this->payment->installment_label,
            'status' => $this->payment->status,
        ];
    }
}
