<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_required')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('files', function (Blueprint $table) {
            $table->foreignId('document_definition_id')->nullable()->constrained()->nullOnDelete()->after('workspace_id');
            $table->string('status')->default('pending')->after('document_definition_id');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete()->after('status');
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->text('rejection_reason')->nullable()->after('reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropForeign(['document_definition_id']);
            $table->dropColumn(['document_definition_id', 'status', 'reviewed_by', 'reviewed_at', 'rejection_reason']);
        });

        Schema::dropIfExists('document_definitions');
    }
};
