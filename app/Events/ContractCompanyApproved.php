<?php

namespace App\Events;

use App\Models\Contract;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ContractCompanyApproved
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Contract $contract;
    public bool $fromPaymentReview;

    public function __construct(Contract $contract, bool $fromPaymentReview = false)
    {
        $this->contract = $contract;
        $this->fromPaymentReview = $fromPaymentReview;
    }
}
