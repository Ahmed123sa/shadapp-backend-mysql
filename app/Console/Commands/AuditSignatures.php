<?php

namespace App\Console\Commands;

use App\Support\SignatureValue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Audits stored signature columns for path-like or URL-like values that do
 * not resolve to a valid `/storage/signatures/...` image (historical remnants
 * of unrestricted typed-signature input). Read-only; exits non-zero when any
 * suspicious row is found.
 */
class AuditSignatures extends Command
{
    protected $signature = 'signatures:audit';
    protected $description = 'Report any stored signature values that look like paths or URLs outside /storage/signatures/';

    /** @var array<int, array{string, string}> */
    private const COLUMNS = [
        ['clients', 'signature_data'],
        ['users', 'signature_data'],
        ['contracts', 'client_signature_data'],
        ['contracts', 'company_signature_data'],
        ['approvals', 'signature'],
    ];

    public function handle(): int
    {
        $flagged = 0;

        foreach (self::COLUMNS as [$table, $column]) {
            DB::table($table)
                ->select(['id', $column])
                ->whereNotNull($column)
                ->where($column, '!=', '')
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($table, $column, &$flagged): void {
                    foreach ($rows as $row) {
                        $value = (string) $row->{$column};
                        if ($this->looksLikePathOrUrl($value) && ! SignatureValue::isImage($value)) {
                            $flagged++;
                            $this->warn("{$table}.{$column} [id={$row->id}]: {$value}");
                        }
                    }
                });
        }

        if ($flagged > 0) {
            $this->error("Found {$flagged} suspicious signature value(s).");
            return self::FAILURE;
        }

        $this->info('All stored signature values are clean.');
        return self::SUCCESS;
    }

    private function looksLikePathOrUrl(string $value): bool
    {
        return str_contains($value, '/')
            || str_contains($value, '\\')
            || str_contains($value, '..')
            || preg_match('#^https?://#i', $value) === 1
            || stripos($value, 'storage') !== false;
    }
}
