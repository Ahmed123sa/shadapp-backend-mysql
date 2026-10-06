<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Login details for an account someone else created for the recipient: an
 * account manager (made by the super admin), a manager's assistant, or a
 * client's sub-user.
 *
 * Deliberately NOT queued, for the same reason as ClientWelcomeMail: it
 * carries a plaintext password, and a queued job would write that password
 * into the `jobs` / `failed_jobs` tables. Sending is infrequent and a single
 * message, so the caller waiting on one SMTP round-trip is fine.
 */
class AccountCredentialsMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $email,
        public string $password,
        public string $roleLabel,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'بيانات دخولك إلى ShadApp');
    }

    public function content(): Content
    {
        return new Content(
            htmlString: view('emails.account-credentials', [
                'name' => $this->name,
                'email' => $this->email,
                'password' => $this->password,
                'roleLabel' => $this->roleLabel,
            ])->render(),
        );
    }
}
