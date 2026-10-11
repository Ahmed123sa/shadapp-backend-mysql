<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the columns the hot queries actually filter and sort on
 * (status + date). Index-only: no column, row or constraint is touched, so
 * this is safe to run on a database that already holds real data. Each
 * index is skipped if it already exists, so a re-run is harmless.
 *
 * Who uses which:
 *  - payments (status, due_date)        payments:send-reminders (daily)
 *  - payments / contracts / approvals
 *    (status, created_at)               /dashboard/stats, pending approvals,
 *                                       reports' monthly sums
 *  - meetings (status, scheduled_at)    meetings:send-reminders (every 30 min)
 *                                       and meetings:update-statuses (every 5)
 *  - chat_messages (workspace_id,
 *    created_at)                        the chat's "latest 100" per workspace
 *  - audit_logs (created_at)            the audit log, newest first, paginated
 *  - notifications (notifiable_type,
 *    notifiable_id, created_at)         each user's notifications, newest first
 */
return new class extends Migration
{
    private const INDEXES = [
        'payments' => [
            'payments_status_due_date_index' => ['status', 'due_date'],
            'payments_status_created_at_index' => ['status', 'created_at'],
        ],
        'contracts' => [
            'contracts_status_created_at_index' => ['status', 'created_at'],
        ],
        'approvals' => [
            'approvals_status_created_at_index' => ['status', 'created_at'],
        ],
        'meetings' => [
            'meetings_status_scheduled_at_index' => ['status', 'scheduled_at'],
        ],
        'chat_messages' => [
            'chat_messages_workspace_id_created_at_index' => ['workspace_id', 'created_at'],
        ],
        'audit_logs' => [
            'audit_logs_created_at_index' => ['created_at'],
        ],
        'notifications' => [
            'notifications_notifiable_created_at_index' => ['notifiable_type', 'notifiable_id', 'created_at'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                if (Schema::hasIndex($table, $name)) {
                    continue;
                }
                Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach (array_keys($indexes) as $name) {
                if (Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
                }
            }
        }
    }
};
