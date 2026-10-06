<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manager assistants (MANAGER_ASSISTANT_PLAN.md §4.1): a staff user with
 * role = 'manager_assistant' that belongs to one account manager.
 * deactivated_by_parent tells "switched off because the manager was" apart
 * from "switched off by the manager", so reactivating the manager restores
 * only the first kind.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('parent_manager_id')->nullable()->after('super_admin_id')
                ->constrained('users')->restrictOnDelete();
            $table->json('assistant_permissions')->nullable()->after('parent_manager_id');
            $table->boolean('deactivated_by_parent')->default(false)->after('assistant_permissions');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_manager_id');
            $table->dropColumn(['assistant_permissions', 'deactivated_by_parent']);
        });
    }
};
