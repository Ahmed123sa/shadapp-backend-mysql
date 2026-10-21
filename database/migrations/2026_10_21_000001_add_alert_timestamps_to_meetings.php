<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the "starts in 5 minutes" and "has started" alerts went out for a
 * meeting (meetings:send-alerts), so each is sent once. Both are cleared when
 * the meeting is moved to another time (Meeting::booted).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->timestamp('soon_notified_at')->nullable()->after('ended_at');
            $table->timestamp('start_notified_at')->nullable()->after('soon_notified_at');
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropColumn(['soon_notified_at', 'start_notified_at']);
        });
    }
};
