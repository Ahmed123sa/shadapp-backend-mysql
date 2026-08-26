<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('date_of_birth');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->string('location_address')->nullable()->after('longitude');
            $table->timestamp('location_updated_at')->nullable()->after('location_address');
            $table->string('location_updated_by_ip')->nullable()->after('location_updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'location_address', 'location_updated_at', 'location_updated_by_ip']);
        });
    }
};
