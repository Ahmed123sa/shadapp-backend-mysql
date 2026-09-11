<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Mpdf\Mpdf;

/**
 * Builds the Mpdf instances used by ContractPdfService and ApprovalPdfService.
 *
 * The reason this exists rather than each service calling `new Mpdf(...)`:
 * mPDF defaults its scratch directory to `vendor/mpdf/mpdf/tmp/mpdf`, and on a
 * deployed server that path is owned by whoever ran `composer install`, not by
 * the web server user — so the constructor throws
 * "Temporary files directory ... is not writable" and every request that
 * touches a PDF returns a 500. Chmod-ing the vendor path fixes it only until
 * the next deploy wipes and reinstalls `vendor/`.
 *
 * Pointing `tempDir` at storage/ instead keeps the scratch space inside the
 * directory the application already owns and already requires to be writable,
 * so it survives deploys. The directory is created on demand because a fresh
 * checkout won't have it (storage/app is gitignored apart from .gitignore).
 */
class MpdfFactory
{
    /** Shared page setup — Arabic-aware, A4, matching margins. */
    private const DEFAULTS = [
        'default_font' => 'dejavusans',
        'mode' => 'ar',
        'autoArabic' => true,
        'format' => 'A4',
        'margin_top' => 10,
        'margin_bottom' => 20,
        'margin_left' => 10,
        'margin_right' => 10,
    ];

    /**
     * @param array<string, mixed> $overrides
     */
    public function make(array $overrides = []): Mpdf
    {
        $tempDir = storage_path('app/mpdf');
        File::ensureDirectoryExists($tempDir);

        return new Mpdf(array_merge(self::DEFAULTS, ['tempDir' => $tempDir], $overrides));
    }
}
