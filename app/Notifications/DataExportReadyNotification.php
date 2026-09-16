<?php

namespace App\Notifications;

use App\Models\DataExport;

/**
 * Mirrors app/Notifications/DataExportReadyNotification.php from
 * shadapp-backend (Postgres) verbatim — no database-specific code involved.
 *
 * Sent to whoever requested a DataExport once App\Jobs\GenerateDataExport
 * finishes — the request is async (DATA_SAFETY_PLAN.md §3.5.2), so without
 * this the requester has no way to know the archive is ready short of
 * refreshing the exports list themselves.
 */
class DataExportReadyNotification extends BaseNotification
{
    public DataExport $dataExport;

    public function __construct(DataExport $dataExport)
    {
        $this->dataExport = $dataExport;
    }

    public function toDatabase($notifiable): array
    {
        if ($this->dataExport->status === DataExport::STATUS_FAILED) {
            return [
                'type' => 'data_export_failed',
                'data_export_id' => $this->dataExport->id,
                'scope' => $this->dataExport->scope,
                'message' => 'فشل إنشاء ملف التصدير — حاول مرة أخرى',
            ];
        }

        return [
            'type' => 'data_export_ready',
            'data_export_id' => $this->dataExport->id,
            'scope' => $this->dataExport->scope,
            'message' => 'ملف التصدير جاهز للتحميل',
        ];
    }

    public function toFcm($notifiable): array
    {
        $failed = $this->dataExport->status === DataExport::STATUS_FAILED;

        return [
            'title' => $failed ? 'فشل التصدير' : 'التصدير جاهز',
            'body' => $failed ? 'فشل إنشاء ملف التصدير — حاول مرة أخرى' : 'ملف التصدير جاهز للتحميل',
            'data' => [
                'type' => $failed ? 'data_export.failed' : 'data_export.ready',
                'id' => (string) $this->dataExport->id,
            ],
        ];
    }

    public function toBroadcast($notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
