<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->timestamp('zoom_started_at')->nullable()->after('ended_at');
            $table->timestamp('zoom_ended_at')->nullable()->after('zoom_started_at');
            $table->json('zoom_attendees')->nullable()->after('zoom_ended_at');
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropColumn(['zoom_started_at', 'zoom_ended_at', 'zoom_attendees']);
        });
    }
};
