<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Mirrors app/Models/DataExport.php from shadapp-backend (Postgres)
 * verbatim — no database-specific code involved.
 *
 * DATA_SAFETY_PLAN.md §3 — a scoped, filtered data-export request. See
 * App\Services\DataExportService for what actually goes in the archive and
 * App\Jobs\GenerateDataExport for how a row here goes from 'pending' to
 * 'ready'.
 */
class DataExport extends Model
{
    use HasFactory;

    public const SCOPE_SYSTEM = 'system';
    public const SCOPE_MANAGER = 'manager';
    public const SCOPE_CLIENT = 'client';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_READY = 'ready';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'scope', 'scope_id', 'status', 'file_path', 'file_size', 'error', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'file_size' => 'integer',
        ];
    }

    public function requestedBy(): MorphTo
    {
        return $this->morphTo();
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    /**
     * Only ever exposed once the archive is actually sitting on disk and not
     * past its own expiry — this is the "second check" DATA_SAFETY_PLAN.md
     * §3.5.3 asks for, done here rather than by re-running the request-time
     * Policy (which is meaningless against an unauthenticated signed URL —
     * see App\Http\Controllers\DataExportDownloadController's docblock).
     * The signature itself is what actually gates the download route
     * ('signed' middleware); a stale or dead row just never gets a link.
     */
    public function getDownloadUrlAttribute(): ?string
    {
        if (! $this->isReady() || $this->isExpired() || ! $this->file_path) {
            return null;
        }

        if (! Storage::disk('local')->exists($this->file_path)) {
            return null;
        }

        return URL::temporarySignedRoute('exports.download', now()->addMinutes(15), ['dataExport' => $this->id]);
    }
}
