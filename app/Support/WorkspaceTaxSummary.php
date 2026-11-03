<?php

namespace App\Support;

use App\Models\SystemSetting;
use App\Models\Workspace;

/**
 * The contracts total, tax and grand total of a workspace — the figure the
 * client sees on the payments page. It lives here so the payments page and the
 * client assistant read the same number instead of two copies of the same sum.
 * The array it returns is exactly what GET /workspaces/{id}/payments has always
 * sent as "tax_summary".
 */
class WorkspaceTaxSummary
{
    public static function for(Workspace $workspace): array
    {
        $client = $workspace->client;

        $contractsTotal = $workspace->contracts()
            ->whereIn('status', ['company_approved', 'completed'])
            ->sum('value');

        $taxPercentage = 0;
        $isBusiness = $client->client_type === 'business';
        try {
            $taxPercentage = (float) SystemSetting::getValue('corporate_tax_percentage', 0);
        } catch (\Exception $e) {
            $taxPercentage = 0;
        }
        $taxAmount = $isBusiness ? ($contractsTotal * $taxPercentage / 100) : 0;

        return [
            'contracts_total' => (float) $contractsTotal,
            'tax_percentage' => $isBusiness ? $taxPercentage : 0,
            'tax_amount' => $taxAmount,
            'grand_total' => $contractsTotal + $taxAmount,
        ];
    }
}
