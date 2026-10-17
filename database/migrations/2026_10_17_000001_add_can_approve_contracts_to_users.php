<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STAFF_TEAMS_PLAN.md §٣.٤ — the super admin can let a specific account
 * manager company-approve contracts for their own clients. Off by default;
 * adding a nullable-free boolean with a default is safe on live data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_approve_contracts')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('can_approve_contracts');
        });
    }
};
