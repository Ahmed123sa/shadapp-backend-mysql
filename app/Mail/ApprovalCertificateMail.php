<?php

namespace App\Mail;

use App\Models\Approval;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ApprovalCertificateMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public Approval $approval;

    public function __construct(Approval $approval)
    {
        $this->approval = $approval;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'شهادة الموافقة: ' . ($this->approval->reference_no ?? ''),
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: view('emails.approval-certificate', ['approval' => $this->approval])->render(),
        );
    }
}
