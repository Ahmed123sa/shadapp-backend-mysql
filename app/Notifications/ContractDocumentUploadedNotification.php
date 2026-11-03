<?php

namespace App\Notifications;

use App\Models\FileEntry;

/**
 * Staff (the client's manager and the super admins) are told the client
 * uploaded a file for one of a contract's required documents, so it can be
 * reviewed. Carries only names and ids; never the file link.
 */
class ContractDocumentUploadedNotification extends BaseNotification
{
    public FileEntry $file;

    public function __construct(FileEntry $file)
    {
        $this->file = $file;
    }

    private function docName(): string
    {
        return (string) ($this->file->contractRequiredDocument?->name ?: $this->file->name);
    }

    private function contractTitle(): string
    {
        return (string) ($this->file->contractRequiredDocument?->contract?->title ?? '');
    }

    private function clientName(): string
    {
        return (string) ($this->file->workspace?->client?->company_name ?? '');
    }

    public function toDatabase($notifiable): array
    {
        $doc = $this->docName();
        $contract = $this->contractTitle();
        $client = $this->clientName();

        return [
            'type' => 'contract_document_uploaded',
            'file_id' => $this->file->id,
            'contract_id' => $this->file->contract_id ?? $this->file->contractRequiredDocument?->contract_id,
            'workspace_id' => $this->file->workspace_id,
            'client_id' => $this->file->workspace?->client_id,
            'title' => 'مستند جديد للمراجعة',
            'title_en' => 'New document to review',
            'message' => "{$client} رفع {$doc}" . ($contract !== '' ? " في عقد {$contract}" : '') . '، محتاج مراجعة.',
            'message_en' => "{$client} uploaded {$doc}" . ($contract !== '' ? " for contract {$contract}" : '') . '. It needs review.',
        ];
    }

    public function toFcm($notifiable): array
    {
        return [
            'title' => 'مستند جديد للمراجعة',
            'body' => "{$this->clientName()} رفع {$this->docName()}، محتاج مراجعة.",
            'title_en' => 'New document to review',
            'body_en' => "{$this->clientName()} uploaded {$this->docName()}. It needs review.",
            'data' => [
                'type' => 'contract',
                'id' => (string) ($this->file->contract_id ?? $this->file->contractRequiredDocument?->contract_id ?? ''),
                'workspace_id' => (string) $this->file->workspace_id,
                'client_id' => (string) ($this->file->workspace?->client_id ?? ''),
            ],
        ];
    }

    public function toBroadcast($notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
