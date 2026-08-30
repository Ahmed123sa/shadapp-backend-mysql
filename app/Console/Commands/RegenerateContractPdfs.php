<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Services\ContractPdfService;
use Illuminate\Console\Command;

class RegenerateContractPdfs extends Command
{
    protected $signature = 'contracts:regenerate-pdfs';
    protected $description = 'Rebuild every contract PDF from current database data';

    public function handle(ContractPdfService $service): void
    {
        $contracts = Contract::with('workspace.client')->get();
        $regenerated = 0;

        foreach ($contracts as $contract) {
            try {
                $url = (string) $contract->pdf_url;
                $both = str_contains($url, '-signed') && !str_contains($url, '-client-signed');
                if ($url === '') {
                    $both = in_array($contract->status, ['company_approved', 'completed'], true)
                        || $contract->company_signature_data !== null;
                }
                $service->generateByVariant($contract, $both);
                $regenerated++;
            } catch (\Throwable $e) {
                $this->error("contract {$contract->id}: {$e->getMessage()}");
            }
        }

        $this->info("Regenerated {$regenerated} contract PDF(s).");
    }
}