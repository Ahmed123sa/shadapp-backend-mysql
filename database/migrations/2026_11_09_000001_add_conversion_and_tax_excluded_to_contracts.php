<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two contract options.
 *
 * conversion_percentage: ads contracts only. The dollar-conversion fee, as a
 * share of the ad budget, charged on top of the contract value. Null (or 0)
 * means no conversion fee, so every existing contract stays as it is.
 *
 * tax_excluded: switch that takes the contract out of tax. Default false.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->decimal('conversion_percentage', 5, 2)->nullable()->after('ads_percentage');
            $table->boolean('tax_excluded')->default(false)->after('conversion_percentage');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', fn (Blueprint $t) => $t->dropColumn(['conversion_percentage', 'tax_excluded']));
    }
};
