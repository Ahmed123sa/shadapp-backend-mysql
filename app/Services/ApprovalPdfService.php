<?php

namespace App\Services;

use App\Models\Approval;
use App\Support\SignatureValue;
use Mpdf\Mpdf;
use Illuminate\Support\Facades\Storage;

class ApprovalPdfService
{
    public function generateCertificate(Approval $approval): string
    {
        $files = $approval->files()->get();
        $requester = $approval->requester;
        $workspace = $approval->workspace;
        $client = $workspace?->client;

        $signatureIsImage = SignatureValue::isImage($approval->signature);
        $signatureImagePath = SignatureValue::diskPath($approval->signature);

        $html = view('pdf.approval-certificate', [
            'approval' => $approval,
            'files' => $files,
            'requester' => $requester,
            'client' => $client,
            'signatureIsImage' => $signatureIsImage,
            'signatureImagePath' => $signatureImagePath,
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

        $filename = 'approval-' . $approval->reference_no . '.pdf';
        $path = 'approval-certificates/' . $filename;
        Storage::disk('public')->put($path, $mpdf->Output('', 'S'));

        return Storage::url($path);
    }
}
