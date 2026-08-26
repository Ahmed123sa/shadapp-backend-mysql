<?php

namespace App\Mail;

use App\Models\Contract;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContractReminderMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public Contract $contract;
    public int $daysPending;

    public function __construct(Contract $contract, int $daysPending = 0)
    {
        $this->contract = $contract;
        $this->daysPending = $daysPending;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'تذكير: العقد ' . $this->contract->title . ' ينتظر مراجعتك',
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: view('emails.contract-reminder', ['contract' => $this->contract, 'daysPending' => $this->daysPending])->render(),
        );
    }
}
