<?php

namespace App\Events;

use App\Models\Client;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ClientCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Client $client;
    public string $password;

    public function __construct(Client $client, string $password)
    {
        $this->client = $client;
        $this->password = $password;
    }
}
