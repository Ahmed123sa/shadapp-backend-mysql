<?php

namespace App\Mail;

use App\Models\Contract;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Queue\SerializesModels;

class ContractCompanyApprovedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public Contract $contract;
    public ?string $pdfPath;

    public function __construct(Contract $contract, ?string $pdfPath = null)
    {
        $this->contract = $contract;
        $this->pdfPath = $pdfPath;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'تم اعتماد العقد نهائياً: ' . $this->contract->title,
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: view('emails.contract-company-approved', ['contract' => $this->contract])->render(),
        );
    }

    public function attachments(): array
    {
        if ($this->pdfPath) {
            $fullPath = storage_path('app/public/' . str_replace('/storage/', '', $this->pdfPath));
            if (file_exists($fullPath)) {
                return [Attachment::fromPath($fullPath)->as('contract-' . $this->contract->id . '-signed.pdf')->withMime('application/pdf')];
            }
        }
        return [];
    }
}
