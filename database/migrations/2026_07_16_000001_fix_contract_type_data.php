<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $workspaces = DB::table('contracts')
            ->select('workspace_id')
            ->groupBy('workspace_id')
            ->get();

        foreach ($workspaces as $ws) {
            $firstContractId = DB::table('contracts')
                ->where('workspace_id', $ws->workspace_id)
                ->orderBy('id')
                ->value('id');

            if ($firstContractId) {
                DB::table('contracts')
                    ->where('id', $firstContractId)
                    ->update(['contract_type' => 'main']);

                DB::table('contracts')
                    ->where('workspace_id', $ws->workspace_id)
                    ->where('id', '!=', $firstContractId)
                    ->update(['contract_type' => 'additional']);
            }
        }
    }

    public function down(): void
    {
        DB::table('contracts')->update(['contract_type' => 'main']);
    }
};
