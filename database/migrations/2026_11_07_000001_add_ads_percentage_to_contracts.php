<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ads contracts: the agency's management fee as a share of the contract value
 * (inside it, not on top). Null means a normal contract, so every existing
 * contract stays exactly as it is. Display only: the value, the tax and the
 * total are not touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->decimal('ads_percentage', 5, 2)->nullable()->after('tax_percentage');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', fn (Blueprint $t) => $t->dropColumn('ads_percentage'));
    }
};
