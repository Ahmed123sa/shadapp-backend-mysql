<?php

namespace App\Events;

use App\Models\ChatMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class MessageUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public ChatMessage $message) {}

    public function broadcastOn(): array
    {
        // See MessageSent::broadcastOn() — must match the private channel
        // name that routes/channels.php authorizes.
        return [new PrivateChannel('workspace.' . $this->message->workspace_id)];
    }

    public function broadcastAs(): string
    {
        return 'message.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'message' => $this->message->load('sender'),
        ];
    }
}
