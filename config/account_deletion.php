<?php

/*
|--------------------------------------------------------------------------
| In-app account deletion (App Store Guideline 5.1.1(v))
|--------------------------------------------------------------------------
|
| DELETE /api/auth/account. Apple requires apps that offer account creation
| to offer account deletion. This switch exists so the endpoint can be turned
| off from the server (ACCOUNT_DELETION_ENABLED=false in .env, then
| `php artisan config:clear` or a restart) without touching code.
|
| Turning it off only stops NEW deletions: the endpoint answers 403 with a
| message. Accounts already deleted stay deleted — their personal data was
| scrubbed and cannot be restored.
|
*/

return [
    'enabled' => (bool) env('ACCOUNT_DELETION_ENABLED', true),
];
