<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sub_users', function (Blueprint $table) {
            $table->json('permissions')->nullable()->after('password');
            $table->string('avatar_url')->nullable()->after('permissions');
            $table->string('phone')->nullable()->after('avatar_url');
        });
    }

    public function down(): void
    {
        Schema::table('sub_users', function (Blueprint $table) {
            $table->dropColumn(['permissions', 'avatar_url', 'phone']);
        });
    }
};
