<?php

namespace App\Services;

use App\Models\Approval;
use App\Support\SignatureValue;
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

        // Built through the factory so the scratch directory lands in
        // storage/ rather than vendor/ — see MpdfFactory for why.
        $mpdf = app(MpdfFactory::class)->make();
        $mpdf->WriteHTML($html);

        $filename = 'approval-' . $approval->reference_no . '.pdf';
        $path = 'approval-certificates/' . $filename;
        Storage::disk('public')->put($path, $mpdf->Output('', 'S'));

        return Storage::url($path);
    }
}
