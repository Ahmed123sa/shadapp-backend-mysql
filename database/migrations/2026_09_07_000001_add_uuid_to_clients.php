<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The dashboard's client detail page (/dashboard/clients/{id}) shows the
 * client's raw numeric primary key in the browser URL. That's not an access
 * control gap on its own — Client::class routes are already guarded by
 * per-tenant authorization (see the workspace-isolation middleware/policies
 * from the IDOR fix) — but a sequential integer still lets anyone glance at
 * the URL bar and guess how many clients exist or which ID belongs to which
 * company. A random UUID shown in the URL instead removes that leak without
 * touching how clients are actually authorized.
 *
 * This column is purely additive: the numeric `id` stays the primary key
 * everywhere (mobile app, other backend code, existing links) — see
 * Client::resolveRouteBinding(), which accepts either value. Nothing that
 * already passes a numeric client ID breaks.
 *
 * (Mirrored from shadapp-backend per the dual-backend sync convention.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id');
        });

        // Backfill existing rows before the column is made unique below —
        // chunked so this stays safe on a clients table of any size.
        DB::table('clients')->select('id')->whereNull('uuid')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                DB::table('clients')->where('id', $row->id)->update(['uuid' => (string) Str::uuid()]);
            }
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->unique('uuid');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->dropColumn('uuid');
        });
    }
};
