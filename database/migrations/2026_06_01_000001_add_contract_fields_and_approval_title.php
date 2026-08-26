<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->date('start_date')->nullable()->after('title');
            $table->date('end_date')->nullable()->after('start_date');
            $table->timestamp('archived_at')->nullable()->after('company_signed_at');
        });

        Schema::table('approvals', function (Blueprint $table) {
            $table->string('title')->nullable()->after('workspace_id');
            $table->text('description')->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn(['start_date', 'end_date', 'archived_at']);
        });

        Schema::table('approvals', function (Blueprint $table) {
            $table->dropColumn(['title', 'description']);
        });
    }
};
