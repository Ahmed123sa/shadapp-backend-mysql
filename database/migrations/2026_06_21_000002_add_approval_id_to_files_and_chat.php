<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->foreignId('approval_id')->nullable()->constrained()->nullOnDelete()->after('contract_id');
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->foreignId('approval_id')->nullable()->constrained()->nullOnDelete()->after('contract_id');
            $table->boolean('action_taken')->default(false)->after('requires_action');
            $table->string('action_result')->nullable()->after('action_taken');
            $table->timestamp('responded_at')->nullable()->after('action_result');
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropForeign(['approval_id']);
            $table->dropColumn('approval_id');
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropForeign(['approval_id']);
            $table->dropColumn(['approval_id', 'action_taken', 'action_result', 'responded_at']);
        });
    }
};
