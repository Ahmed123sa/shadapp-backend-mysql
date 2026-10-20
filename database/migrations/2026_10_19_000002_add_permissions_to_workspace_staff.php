<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-client permissions of an assigned staff member (PLAN_2 §٣.٢). NULL means
 * every permission, so all existing assignments keep working unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_staff', function (Blueprint $table) {
            $table->json('permissions')->nullable()->after('assigned_by');
        });
    }

    public function down(): void
    {
        Schema::table('workspace_staff', function (Blueprint $table) {
            $table->dropColumn('permissions');
        });
    }
};
