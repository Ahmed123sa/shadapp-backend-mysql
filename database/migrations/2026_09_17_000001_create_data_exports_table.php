<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors 2026_09_17_000001_create_data_exports_table.php from
 * shadapp-backend (Postgres) verbatim — no Postgres-specific syntax
 * involved.
 *
 * DATA_SAFETY_PLAN.md §3.5.1 — tracks scoped data-export requests (system /
 * manager / client). One row per request; the actual archive is built
 * asynchronously by App\Jobs\GenerateDataExport since a client with many
 * files can take minutes to zip (see the job's own docblock).
 *
 * `requested_by` is a morph even though only App\Models\User can request an
 * export today (clients/sub-users never see this feature) — kept consistent
 * with every other actor-reference column in this schema (audit_logs.user_id
 * is the one exception, and only because it predates this convention).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_exports', function (Blueprint $table) {
            $table->id();
            $table->morphs('requested_by');
            // system | manager | client — see App\Models\DataExport.
            $table->string('scope');
            // The manager's or client's id for the corresponding scope; null
            // for 'system'.
            $table->unsignedBigInteger('scope_id')->nullable();
            // pending | processing | ready | failed
            $table->string('status')->default('pending');
            // Relative path on the 'local' disk (storage/app/private by
            // default) — deliberately not the 'public' disk, since this is
            // the one thing in the whole app that must never be reachable by
            // a guessable URL.
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->text('error')->nullable();
            // 7 days from completion — see App\Console\Commands\PruneDataExports.
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_exports');
    }
};
