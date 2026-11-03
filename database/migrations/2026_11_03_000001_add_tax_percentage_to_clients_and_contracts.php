<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tax per client, locked per contract. The tax % used to be one global setting
 * read live everywhere, so changing it re-priced every contract already signed.
 * Now a business client carries its own %, and each contract keeps the % that
 * was in force the moment the client approved it. Existing business clients and
 * already-approved contracts get today's global value once, so nothing the
 * current numbers show changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->decimal('tax_percentage', 5, 2)->nullable()->after('client_type');
        });
        Schema::table('contracts', function (Blueprint $table) {
            $table->decimal('tax_percentage', 5, 2)->nullable()->after('currency');
        });

        $global = (float) (DB::table('system_settings')->where('key', 'corporate_tax_percentage')->value('value') ?? 0);

        DB::table('clients')->where('client_type', 'business')->update(['tax_percentage' => $global]);

        $businessIds = DB::table('clients')->where('client_type', 'business')->pluck('id');
        $workspaceIds = DB::table('workspaces')->whereIn('client_id', $businessIds)->pluck('id');
        DB::table('contracts')
            ->whereIn('workspace_id', $workspaceIds)
            ->whereIn('status', ['client_approved', 'company_approved', 'completed'])
            ->update(['tax_percentage' => $global]);
    }

    public function down(): void
    {
        Schema::table('contracts', fn (Blueprint $t) => $t->dropColumn('tax_percentage'));
        Schema::table('clients', fn (Blueprint $t) => $t->dropColumn('tax_percentage'));
    }
};
