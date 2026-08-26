<?php

namespace App\Domains\Chat;

use App\Models\ChatMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class MessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public ChatMessage $message) {}

    public function broadcastOn(): array
    {
        // Must be PrivateChannel: routes/channels.php authorizes
        // "workspace.{id}" as a *private* channel (Pusher/Reverb protocol
        // name "private-workspace.{id}"). A plain Channel here broadcasts on
        // "workspace.{id}" with no auth check at all — anyone who guesses a
        // workspace id could subscribe and read every chat message and
        // payment update in it, and it also never reaches clients who
        // (correctly) subscribed to the private name.
        return [new PrivateChannel('workspace.' . $this->message->workspace_id)];
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    public function broadcastWith(): array
    {
        return [
            'message' => $this->message->load('sender'),
        ];
    }
}
