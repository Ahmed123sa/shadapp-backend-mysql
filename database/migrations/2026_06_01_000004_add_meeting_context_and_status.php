<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete()->after('workspace_id');
            $table->foreignId('approval_id')->nullable()->constrained()->nullOnDelete()->after('contract_id');
            $table->string('status')->default('scheduled')->after('duration_minutes');
            $table->text('notes')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropForeign(['contract_id']);
            $table->dropForeign(['approval_id']);
            $table->dropColumn(['contract_id', 'approval_id', 'status', 'notes']);
        });
    }
};
