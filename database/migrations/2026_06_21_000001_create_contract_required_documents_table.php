<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_required_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_required')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('files', function (Blueprint $table) {
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete()->after('workspace_id');
            $table->foreignId('contract_required_document_id')->nullable()->constrained()->nullOnDelete()->after('contract_id');
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropForeign(['contract_required_document_id']);
            $table->dropForeign(['contract_id']);
            $table->dropColumn(['contract_required_document_id', 'contract_id']);
        });

        Schema::dropIfExists('contract_required_documents');
    }
};
