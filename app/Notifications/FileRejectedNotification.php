<?php

namespace App\Notifications;

use App\Models\FileEntry;

/**
 * The client (and the sub-users allowed to see files) is told a file was
 * rejected and why, so they can upload a replacement. Carries only the file
 * name, the reason and ids; never the file link.
 */
class FileRejectedNotification extends BaseNotification
{
    public FileEntry $file;

    public function __construct(FileEntry $file)
    {
        $this->file = $file;
    }

    private function label(): string
    {
        return (string) ($this->file->contractRequiredDocument?->name ?: $this->file->name);
    }

    public function toDatabase($notifiable): array
    {
        $label = $this->label();
        $reason = trim((string) $this->file->rejection_reason);

        return [
            'type' => 'file_rejected',
            'file_id' => $this->file->id,
            'contract_id' => $this->file->contract_id ?? $this->file->contractRequiredDocument?->contract_id,
            'workspace_id' => $this->file->workspace_id,
            'client_id' => $this->file->workspace?->client_id,
            'title' => 'تم رفض ملف',
            'title_en' => 'A file was rejected',
            'message' => "تم رفض {$label}" . ($reason !== '' ? ": {$reason}" : '') . '. ارفع ملف بديل.',
            'message_en' => "{$label} was rejected" . ($reason !== '' ? ": {$reason}" : '') . '. Please upload a replacement.',
            'rejection_reason' => $reason !== '' ? $reason : null,
        ];
    }

    public function toFcm($notifiable): array
    {
        return [
            'title' => 'تم رفض ملف',
            'body' => "تم رفض {$this->label()}. ارفع ملف بديل.",
            'data' => [
                'type' => 'file_rejected',
                'id' => (string) $this->file->id,
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
