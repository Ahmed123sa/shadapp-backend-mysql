<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company staff members (STAFF_TEAMS_PLAN.md §٣.١): a user with
 * role = 'staff_member'. Archiving keeps the row (and the real name, for the
 * audit log and the chat) — archived_at is when it happened, is_active is
 * what actually blocks sign-in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('removed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
    }
};
