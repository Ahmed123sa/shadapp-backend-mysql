<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soft removal of a manager's assistant. The row stays (so the audit log and
 * chat history keep showing the person's name); removed_at hides them from
 * the manager's team and from the assistant limit, and their login email is
 * freed by the controller.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('removed_at')->nullable()->after('deactivated_by_parent');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('removed_at');
        });
    }
};
