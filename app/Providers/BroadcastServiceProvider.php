<?php

namespace App\Providers;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;
use App\Models\User;
use App\Models\Client;

class BroadcastServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Broadcast::routes([
            'prefix' => 'api',
            // sub_user was missing here, so a sub-user's Sanctum token never
            // authenticated on /api/broadcasting/auth at all — the request
            // 401'd before any channel-level check in routes/channels.php
            // even ran. That cut sub-users out of realtime chat/payment
            // updates entirely despite them having permission to act on
            // their client's workspace everywhere else in the app.
            'middleware' => ['auth.any:sanctum,client,sub_user'],
        ]);

        require base_path('routes/channels.php');
    }
}
