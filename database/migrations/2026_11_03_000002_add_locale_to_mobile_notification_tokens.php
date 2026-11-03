<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The language of the device a push token belongs to, so each push goes out
 * in that device's language. Existing tokens (and old app versions that never
 * send one) stay Arabic, exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mobile_notification_tokens', function (Blueprint $table) {
            $table->string('locale', 5)->default('ar');
        });
    }

    public function down(): void
    {
        Schema::table('mobile_notification_tokens', fn (Blueprint $t) => $t->dropColumn('locale'));
    }
};
