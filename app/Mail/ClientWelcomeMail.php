<?php

namespace App\Mail;

use App\Models\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Deliberately NOT queued, unlike every other mailable in this directory.
 *
 * Queued jobs are serialized into storage (the `jobs` table, and `failed_jobs`
 * on failure — which is retained indefinitely by default). This mailable
 * carries the client's plaintext password, so queueing it would write that
 * password to the database in readable form and leave it there if the job
 * ever fails.
 *
 * The performance argument for queueing doesn't really apply here either:
 * client creation is infrequent and sends exactly one email, so the caller
 * waits on a single SMTP round-trip rather than a fan-out of them.
 *
 * The better long-term fix is to stop emailing passwords at all and send a
 * time-limited password-set link instead; at that point this can be queued
 * like the rest.
 */
class ClientWelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public Client $client;
    public string $password;

    public function __construct(Client $client, string $password)
    {
        $this->client = $client;
        $this->password = $password;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'مرحباً بك في ShadApp',
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: view('emails.client-welcome', ['client' => $this->client, 'password' => $this->password])->render(),
        );
    }
}
