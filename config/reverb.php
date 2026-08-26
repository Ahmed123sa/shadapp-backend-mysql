<?php

return [
    'apps' => [
        [
            'app_id' => env('REVERB_APP_ID'),
            'app_key' => env('REVERB_APP_KEY'),
            'app_secret' => env('REVERB_APP_SECRET'),
            'app_host' => env('REVERB_HOST', 'localhost'),
            'app_port' => env('REVERB_PORT', 8080),
            'app_scheme' => env('REVERB_SCHEME', 'http'),
            'allowed_origins' => ['*'],
            'ping_interval' => env('REVERB_PING_INTERVAL', 30),
            'max_message_size' => env('REVERB_MAX_MESSAGE_SIZE', 10240),
        ],
    ],
];
