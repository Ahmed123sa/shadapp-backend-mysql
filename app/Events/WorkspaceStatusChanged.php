<?php

namespace App\Events;

use App\Models\Workspace;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired from the three call-sites that can activate a workspace
 * (PaymentController@review, ContractController@companyApprove,
 * ContractController@complete). Chiefly benefits the client-portal "first
 * contract" flow (REALTIME_PLAN.md section 2, path A) — that page currently
 * has no realtime subscription at all and polls every 10s waiting for
 * exactly this transition.
 */
class WorkspaceStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public Workspace $workspace) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('workspace.' . $this->workspace->id)];
    }

    public function broadcastAs(): string
    {
        return 'workspace.status_changed';
    }

    public function broadcastWith(): array
    {
        return [
            'workspace_id' => $this->workspace->id,
            'status' => $this->workspace->status,
            'activated_at' => $this->workspace->activated_at?->toIso8601String(),
        ];
    }
}
