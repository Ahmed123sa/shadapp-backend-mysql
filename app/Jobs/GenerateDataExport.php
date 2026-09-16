<?php

namespace App\Jobs;

use App\Models\DataExport;
use App\Notifications\DataExportReadyNotification;
use App\Services\DataExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Mirrors app/Jobs/GenerateDataExport.php from shadapp-backend (Postgres)
 * verbatim — no database-specific code involved.
 *
 * Builds a DataExport's archive in the background (DATA_SAFETY_PLAN.md
 * §3.5.2) — a client with many files can take minutes to zip, which has no
 * business happening inside the HTTP request that created the row. Needs an
 * actual running queue worker; on the live server this is the one part of
 * the whole initiative still blocked on `supervisor/setup.sh` (§0.3) — the
 * same reason every other queued notification doesn't go out there yet.
 * None of that applies locally/in tests, where QUEUE_CONNECTION=sync runs
 * this inline.
 */
class GenerateDataExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Never silently retried: a retry would re-run the whole archive build
     * (minutes of work) and send a second "ready"/"failed" notification for
     * the same request. A failed export is surfaced to the requester, who
     * can just ask again.
     */
    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public DataExport $dataExport)
    {
    }

    public function handle(DataExportService $service): void
    {
        $this->dataExport->update(['status' => DataExport::STATUS_PROCESSING]);

        try {
            $relativePath = $service->build($this->dataExport);

            $this->dataExport->update([
                'status' => DataExport::STATUS_READY,
                'file_path' => $relativePath,
                'file_size' => Storage::disk('local')->size($relativePath),
                'error' => null,
                'expires_at' => now()->addDays(7),
            ]);
        } catch (Throwable $e) {
            // Deliberately not rethrown: the failure is already fully
            // captured on the row itself (status + error message), which is
            // all the requester or an admin needs. Rethrowing would only
            // hand the same failure to Laravel's queue retry/failed_jobs
            // machinery, and with $tries = 1 that machinery does nothing
            // useful here — it would just risk the request that dispatched
            // this job (on the 'sync' driver, e.g. in tests) blowing up
            // instead of getting back its normal 202 response.
            $this->dataExport->update([
                'status' => DataExport::STATUS_FAILED,
                'error' => $e->getMessage(),
            ]);

            $this->dataExport->requestedBy?->notify(new DataExportReadyNotification($this->dataExport->fresh()));

            return;
        }

        $this->dataExport->requestedBy?->notify(new DataExportReadyNotification($this->dataExport->fresh()));
    }
}
