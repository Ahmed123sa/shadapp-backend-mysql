<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * payments.proof_file_url started life as a single varchar URL and became an
 * array of URLs (see Payment::$casts => 'array').
 *
 * This was originally written as raw PostgreSQL DDL, which meant it could not
 * run on MySQL or on the SQLite database the test suite uses. Using the schema
 * builder lets Laravel emit the right DDL per driver.
 *
 * Note on existing rows: Laravel's ->change() converts the column type but does
 * not rewrite row values. Any pre-existing plain-string value would no longer be
 * valid JSON. That is handled explicitly below rather than left to chance.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Wrap any legacy single-URL strings into a JSON array before the type
        // changes, so no row is left holding a value the new type can't parse.
        \Illuminate\Support\Facades\DB::table('payments')
            ->whereNotNull('proof_file_url')
            ->where('proof_file_url', 'not like', '[%')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    \Illuminate\Support\Facades\DB::table('payments')
                        ->where('id', $row->id)
                        ->update(['proof_file_url' => json_encode([$row->proof_file_url])]);
                }
            });

        Schema::table('payments', function (Blueprint $table) {
            $table->json('proof_file_url')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('proof_file_url', 255)->nullable()->change();
        });
    }
};
