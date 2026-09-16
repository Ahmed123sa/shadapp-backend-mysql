<?php

namespace App\Console\Commands;

use App\Models\DataExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Mirrors app/Console/Commands/PruneDataExports.php from shadapp-backend
 * (Postgres) verbatim — no database-specific code involved.
 *
 * DATA_SAFETY_PLAN.md §3.5.3 — a ready export's archive lives for 7 days
 * from completion, then this deletes both the file and the row. Deleting
 * the row outright (rather than clearing file_path and leaving a dangling
 * "ready" record with nothing behind it) matches what the plan actually
 * promises the user: the export "gets deleted", not "gets marked expired".
 *
 * Only 'ready' rows are ever touched — pending/processing/failed rows have
 * no file on disk to begin with and aren't on the 7-day clock (their
 * expires_at is never set; see App\Jobs\GenerateDataExport).
 */
class PruneDataExports extends Command
{
    protected $signature = 'exports:prune';

    protected $description = 'Delete expired data-export archives (DATA_SAFETY_PLAN.md §3.5.3)';

    public function handle(): int
    {
        $expired = DataExport::query()
            ->where('status', DataExport::STATUS_READY)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get();

        foreach ($expired as $export) {
            if ($export->file_path && Storage::disk('local')->exists($export->file_path)) {
                Storage::disk('local')->delete($export->file_path);
            }

            $export->delete();
        }

        $this->info("Pruned {$expired->count()} expired export(s).");

        return self::SUCCESS;
    }
}
