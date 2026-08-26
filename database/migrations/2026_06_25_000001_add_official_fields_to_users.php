<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('official_email')->nullable()->after('email');
            $table->text('signature_data')->nullable()->after('password');
            $table->timestamp('signed_at')->nullable()->after('signature_data');
            $table->string('avatar_url')->nullable()->after('signed_at');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->string('avatar_url')->nullable()->after('signature_data');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['official_email', 'signature_data', 'signed_at', 'avatar_url']);
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('avatar_url');
        });
    }
};
