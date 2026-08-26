<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('client_type')->default('business')->after('payment_status');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->string('proof_file_url')->nullable()->after('proof_file');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('client_type');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('proof_file_url');
        });
    }
};
