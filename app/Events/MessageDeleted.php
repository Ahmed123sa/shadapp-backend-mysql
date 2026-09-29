<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Plain ids, not the model: the row is already gone when this is queued,
 * so serializing a ChatMessage here would fail to restore on the worker.
 */
class MessageDeleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $messageId, public int $workspaceId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('workspace.' . $this->workspaceId)];
    }

    public function broadcastAs(): string
    {
        return 'message.deleted';
    }

    public function broadcastWith(): array
    {
        return ['message_id' => $this->messageId];
    }
}
