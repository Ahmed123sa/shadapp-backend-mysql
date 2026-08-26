<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * contracts.client_signed_at recorded *when* the client approved, but not
 * *what they actually signed with*. The generated PDF instead re-read
 * clients.signature_data at render time — the client's current profile
 * signature, not what was on file the moment they clicked approve. If a
 * client changes or deletes their signature afterward, regenerating the PDF
 * would silently show a different signature than the one actually used to
 * approve. Approvals already avoid this (approvals.signature is a snapshot);
 * contracts should behave the same way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->text('client_signature_data')->nullable()->after('client_signed_at');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('client_signature_data');
        });
    }
};
