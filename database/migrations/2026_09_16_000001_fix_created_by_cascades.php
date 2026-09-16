<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fixes an FK cascade left over from create_core_tables.php: contracts.created_by,
 * approvals.requested_by and meetings.created_by were wired with
 * cascadeOnDelete() instead of nullOnDelete(). Deleting any user — not just a
 * manager assigned to the affected client — cascade-deleted every contract,
 * approval and meeting they had ever created, across every workspace. The
 * correct pattern was already used two lines away in the same file
 * (payments.reviewed_by, users.super_admin_id both use nullOnDelete()), so
 * this was a copy-paste omission, not a deliberate choice. See
 * DATA_SAFETY_PLAN.md §2.4.
 *
 * Each column goes through three steps, in this order: drop the existing FK
 * constraint, make the column nullable, then re-add the FK with
 * nullOnDelete(). All current rows have a value (the columns are NOT NULL
 * today), so there's no data problem going into this — the risk here is
 * entirely in the constraint/column-attribute change itself, not the data.
 *
 * created_by/requested_by turning null after this is expected once a user is
 * deleted — every call site that reads these relations (creator/requester)
 * was audited and already guards against a null relation (SendMeetingReminders,
 * SendApprovalEmailNotification, CreateMeetingChatMessage all predate this
 * migration and were written with a deactivated/reassigned manager in mind,
 * which behaves the same as null for these purposes).
 *
 * Mirrors 2026_09_16_000001_fix_created_by_cascades.php from shadapp-backend
 * (Postgres) verbatim — no MySQL-specific syntax involved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
        });
        Schema::table('contracts', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by')->nullable()->change();
        });
        Schema::table('contracts', function (Blueprint $table) {
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('approvals', function (Blueprint $table) {
            $table->dropForeign(['requested_by']);
        });
        Schema::table('approvals', function (Blueprint $table) {
            $table->unsignedBigInteger('requested_by')->nullable()->change();
        });
        Schema::table('approvals', function (Blueprint $table) {
            $table->foreign('requested_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('meetings', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
        });
        Schema::table('meetings', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by')->nullable()->change();
        });
        Schema::table('meetings', function (Blueprint $table) {
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Restores the cascadeOnDelete FK, but deliberately does NOT force
        // the columns back to NOT NULL — any row created_by/requested_by has
        // turned null since this migration ran (a legitimate, expected state
        // once a user is deleted) would make that reversal fail. Rolling
        // back the nullability itself is not safe to automate; leaving the
        // columns nullable on rollback is the honest reflection of that.
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
        });
        Schema::table('contracts', function (Blueprint $table) {
            $table->foreign('created_by')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('approvals', function (Blueprint $table) {
            $table->dropForeign(['requested_by']);
        });
        Schema::table('approvals', function (Blueprint $table) {
            $table->foreign('requested_by')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('meetings', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
        });
        Schema::table('meetings', function (Blueprint $table) {
            $table->foreign('created_by')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
