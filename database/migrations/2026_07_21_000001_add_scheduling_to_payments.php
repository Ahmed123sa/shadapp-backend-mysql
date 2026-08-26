<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->date('due_date')->nullable()->after('currency');
            $table->string('installment_label')->nullable()->after('due_date');
            $table->boolean('requested_by_manager')->default(false)->after('installment_label');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['due_date', 'installment_label', 'requested_by_manager']);
        });
    }
};
