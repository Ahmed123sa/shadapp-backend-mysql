<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Address;

/**
 * A message from the public support form. Sent synchronously (not queued) so
 * a mail failure is reported to the visitor instead of silently lost.
 */
class SupportRequestMail extends Mailable
{
    use Queueable;

    public function __construct(
        public string $senderName,
        public string $senderEmail,
        public string $subjectLine,
        public string $body,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [new Address($this->senderEmail, $this->senderName)],
            subject: '[ShadApp Support] ' . $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: view('emails.support-request', [
                'senderName' => $this->senderName,
                'senderEmail' => $this->senderEmail,
                'subjectLine' => $this->subjectLine,
                'body' => $this->body,
            ])->render(),
        );
    }
}
