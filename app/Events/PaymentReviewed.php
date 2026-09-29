<?php

namespace App\Events;

use App\Models\Payment;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentReviewed
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Payment $payment;
    public string $action;
    public bool $wasWorkspaceActivated;

    public function __construct(Payment $payment, string $action, bool $wasWorkspaceActivated = false)
    {
        $this->payment = $payment;
        $this->action = $action;
        $this->wasWorkspaceActivated = $wasWorkspaceActivated;
    }
}
