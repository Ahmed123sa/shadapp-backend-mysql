<?php

namespace App\Mail;

use App\Models\Contract;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContractCompletedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public Contract $contract;

    public function __construct(Contract $contract)
    {
        $this->contract = $contract;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'تم إكمال العقد: ' . $this->contract->title,
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: view('emails.contract-completed', ['contract' => $this->contract])->render(),
        );
    }
}
