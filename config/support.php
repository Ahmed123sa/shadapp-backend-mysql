<?php

/*
|--------------------------------------------------------------------------
| Public support form (POST /api/support)
|--------------------------------------------------------------------------
|
| App Store Review requires a public Support URL with a way to contact us.
| Every submission is mailed to this address; if the sender is a known
| client / sub-user, their account manager is copied as well.
|
*/

return [
    'email' => env('SUPPORT_EMAIL', 'support@shadmanagement.co'),
];
