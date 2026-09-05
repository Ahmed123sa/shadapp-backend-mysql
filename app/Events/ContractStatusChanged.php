<?php

namespace App\Events;

use App\Models\Contract;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Realtime counterpart to the contract lifecycle events (ContractSent,
 * ContractClientApproved, ContractCompanyApproved, ContractCompleted), which
 * only trigger notifications/emails and never reach the browser over
 * WebSocket. The frontend (src/lib/echo.ts) has listened for
 * `.contract.status_changed` since before this event existed — see
 * REALTIME_PLAN.md section 3 — so no frontend change is needed for that
 * listener to start working once this fires.
 */
class ContractStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public Contract $contract) {}

    public function broadcastOn(): array
    {
        // Must be PrivateChannel — see MessageSent::broadcastOn() for why a
        // plain Channel here would be a tenant-isolation hole.
        return [new PrivateChannel('workspace.' . $this->contract->workspace_id)];
    }

    public function broadcastAs(): string
    {
        return 'contract.status_changed';
    }

    public function broadcastWith(): array
    {
        return [
            'contract_id' => $this->contract->id,
            'status' => $this->contract->status,
            'contract_type' => $this->contract->contract_type,
            'workspace_id' => $this->contract->workspace_id,
        ];
    }
}
