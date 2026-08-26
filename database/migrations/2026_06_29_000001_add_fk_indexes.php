<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->index('super_admin_id');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->index('manager_id');
        });

        Schema::table('sub_users', function (Blueprint $table) {
            $table->index('client_id');
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->index('client_id');
            $table->index('manager_id');
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->index('workspace_id');
            $table->index('created_by');
        });

        Schema::table('contract_clauses', function (Blueprint $table) {
            $table->index('contract_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->index('workspace_id');
            $table->index('client_id');
            $table->index('reviewed_by');
        });

        Schema::table('approvals', function (Blueprint $table) {
            $table->index('workspace_id');
            $table->index('requested_by');
        });

        Schema::table('approval_certificates', function (Blueprint $table) {
            $table->index('approval_id');
        });

        Schema::table('meetings', function (Blueprint $table) {
            $table->index('workspace_id');
            $table->index('created_by');
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->index('workspace_id');
        });

        Schema::table('files', function (Blueprint $table) {
            $table->index('workspace_id');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn(Blueprint $t) => $t->dropIndex(['super_admin_id']));
        Schema::table('clients', fn(Blueprint $t) => $t->dropIndex(['manager_id']));
        Schema::table('sub_users', fn(Blueprint $t) => $t->dropIndex(['client_id']));
        Schema::table('workspaces', function (Blueprint $t) {
            $t->dropIndex(['client_id']);
            $t->dropIndex(['manager_id']);
        });
        Schema::table('contracts', function (Blueprint $t) {
            $t->dropIndex(['workspace_id']);
            $t->dropIndex(['created_by']);
        });
        Schema::table('contract_clauses', fn(Blueprint $t) => $t->dropIndex(['contract_id']));
        Schema::table('payments', function (Blueprint $t) {
            $t->dropIndex(['workspace_id']);
            $t->dropIndex(['client_id']);
            $t->dropIndex(['reviewed_by']);
        });
        Schema::table('approvals', function (Blueprint $t) {
            $t->dropIndex(['workspace_id']);
            $t->dropIndex(['requested_by']);
        });
        Schema::table('approval_certificates', fn(Blueprint $t) => $t->dropIndex(['approval_id']));
        Schema::table('meetings', function (Blueprint $t) {
            $t->dropIndex(['workspace_id']);
            $t->dropIndex(['created_by']);
        });
        Schema::table('chat_messages', fn(Blueprint $t) => $t->dropIndex(['workspace_id']));
        Schema::table('files', fn(Blueprint $t) => $t->dropIndex(['workspace_id']));
        Schema::table('audit_logs', fn(Blueprint $t) => $t->dropIndex(['user_id']));
    }
};
