<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which company staff members work on which client (STAFF_TEAMS_PLAN.md
 * §٣.٢). Many-to-many: a staff member can be on any number of clients, under
 * any manager, and a client can have several. Moving a client to another
 * manager touches workspaces.manager_id only, so these rows — and therefore
 * the staff — stay with the client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['workspace_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_staff');
    }
};
