<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The super admin hears about every meeting: once when it is created and once
 * 15 minutes before it starts. This marks that reminder as sent so it goes out
 * once per meeting (moving the meeting clears it, see Meeting::booted). Empty
 * for every existing meeting; nothing else is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->timestamp('sa_reminded_at')->nullable()->after('start_notified_at');
        });
    }

    public function down(): void
    {
        Schema::table('meetings', fn (Blueprint $t) => $t->dropColumn('sa_reminded_at'));
    }
};
