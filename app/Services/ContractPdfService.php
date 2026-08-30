<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\SystemSetting;
use App\Support\SignatureValue;
use Mpdf\Mpdf;
use Illuminate\Support\Facades\Storage;

class ContractPdfService
{
    private function buildPdf(Contract $contract, bool $bothSignatures): string
    {
        $client = $contract->workspace->client;

        // Prefer the signature captured at the moment the client actually
        // approved this contract (see ContractController::clientAction). Older
        // contracts approved before that snapshot existed fall back to
        // whatever is on the client's profile now.
        $clientSignature = $contract->client_signature_data ?? $client->signature_data;
        $isImage = SignatureValue::isImage($clientSignature);
        $clientImagePath = SignatureValue::diskPath($clientSignature);

        $requiredDocs = $contract->requiredDocuments()->get();

        $taxPercentage = 0;
        $taxAmount = 0;
        if ($client->client_type === 'business' && $contract->value > 0) {
            try {
                $taxPercentage = (float) SystemSetting::getValue('corporate_tax_percentage', 0);
            } catch (\Exception $e) {
                $taxPercentage = 0;
            }
            $taxAmount = (float) $contract->value * $taxPercentage / 100;
        }

        $currency = $contract->currency ?: 'SAR';
        $currencyMap = [
            'SAR' => 'ر.س',
            'USD' => 'USD',
            'EUR' => 'EUR',
            'AED' => 'د.إ',
            'EGP' => 'ج.م',
            'KWD' => 'د.ك',
            'QAR' => 'ر.ق',
            'BHD' => 'د.ب',
            'OMR' => 'ر.ع',
        ];
        $currencyLabel = $currencyMap[$currency] ?? $currency;

        $html = view('pdf.contract', [
            'contract' => $contract,
            'client' => $client,
            'manager' => $contract->creator,
            'clientSignature' => $clientSignature,
            'clientSignatureIsImage' => $isImage,
            'clientImagePath' => $clientImagePath,
            'companySignature' => $bothSignatures
                ? ($contract->company_signature_data ?? ($contract->creator?->name ?? 'تم الاعتماد'))
                : null,
            'requiredDocuments' => $requiredDocs,
            'taxPercentage' => $taxPercentage,
            'taxAmount' => $taxAmount,
            'currencyLabel' => $currencyLabel,
        ])->render();

        $mpdf = new Mpdf([
            'default_font' => 'dejavusans',
            'mode' => 'ar',
            'autoArabic' => true,
            'format' => 'A4',
            'margin_top' => 10,
            'margin_bottom' => 20,
            'margin_left' => 10,
            'margin_right' => 10,
        ]);
        $mpdf->WriteHTML($html);

        $suffix = $bothSignatures ? '-signed' : '-client-signed';
        $filename = 'contract-' . $contract->id . $suffix . '.pdf';
        $path = 'contracts/' . $filename;
        Storage::disk('public')->put($path, $mpdf->Output('', 'S'));

        $contract->update(['pdf_url' => Storage::url($path)]);

        return Storage::url($path);
    }

    public function generateWithClientSignature(Contract $contract): string
    {
        return $this->buildPdf($contract, false);
    }

    public function generateWithBothSignatures(Contract $contract): string
    {
        return $this->buildPdf($contract, true);
    }

    public function generateByVariant(Contract $contract, bool $bothSignatures): string
    {
        return $this->buildPdf($contract, $bothSignatures);
    }

    /**
     * Guarantee a current PDF exists for the contract's stored pdf_url.
     * If the stored file is missing (e.g. after a database reset/import),
     * regenerate it using the same variant the stored path implies, so
     * signatures are re-read from the database rather than from a stale file.
     */
    public function ensureFreshPdf(Contract $contract): string
    {
        $url = (string) $contract->pdf_url;

        if ($url !== '') {
            $storage = Storage::disk('public');
            $path = 'contracts/' . basename((string) \parse_url($url, PHP_URL_PATH));
            if ($storage->exists($path)) {
                return $url;
            }
        }

        if (str_contains($url, '-client-signed')) {
            return $this->buildPdf($contract, false);
        }

        if (str_contains($url, '-signed')) {
            return $this->buildPdf($contract, true);
        }

        // No stored file yet: decide the variant from the workflow stage.
        $both = in_array($contract->status, ['company_approved', 'completed'], true)
            || $contract->company_signature_data !== null;
        return $this->buildPdf($contract, $both);
    }
}
