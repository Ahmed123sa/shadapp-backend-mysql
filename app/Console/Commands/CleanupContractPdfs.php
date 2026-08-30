<?php

namespace App\Console\Commands;

use App\Models\Contract;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanupContractPdfs extends Command
{
    protected $signature = 'contracts:cleanup-pdfs';
    protected $description = 'Delete contract PDF files no longer referenced by any contract in the database';

    public function handle(): void
    {
        $disk = Storage::disk('public');
        if (!$disk->exists('contracts')) {
            $this->info('No contracts folder present.');
            return;
        }

        $referenced = Contract::whereNotNull('pdf_url')
            ->get()
            ->map(function (Contract $contract) use ($disk) {
                $path = parse_url((string) $contract->pdf_url, PHP_URL_PATH);
                return 'contracts/' . basename((string) $path);
            })
            ->flip();

        $deleted = 0;
        foreach ($disk->files('contracts') as $file) {
            if (!$referenced->has($file)) {
                $disk->delete($file);
                $deleted++;
                $this->line("Deleted stale pdf: {$file}");
            }
        }

        $this->info("Deleted {$deleted} stale PDF file(s).");
    }
}