<?php

namespace App\Support;

use App\Mail\AccountCredentialsMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the login details of a freshly created staff / sub-user account.
 * A mail failure must never fail the request that created the account (the
 * creator still sees the credentials on screen), so it is only logged.
 * The password is never logged.
 */
class CredentialsMailer
{
    public static function send(string $name, string $email, string $password, string $roleLabel): void
    {
        try {
            Mail::to($email)->send(new AccountCredentialsMail($name, $email, $password, $roleLabel));
        } catch (\Throwable $e) {
            Log::warning("Failed to send credentials email to {$email}: " . $e->getMessage());
        }
    }
}
