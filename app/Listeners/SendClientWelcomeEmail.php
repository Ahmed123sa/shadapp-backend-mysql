<?php

namespace App\Listeners;

use App\Events\ClientCreated;
use App\Mail\ClientWelcomeMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendClientWelcomeEmail
{
    public function handleClientCreated(ClientCreated $event): void
    {
        $client = $event->client;

        if ($client->email) {
            try {
                Mail::to($client->email)->send(new ClientWelcomeMail($client, $event->password));
            } catch (\Exception $e) {
                Log::warning('Failed to send welcome email: ' . $e->getMessage());
            }
        }
    }
}
