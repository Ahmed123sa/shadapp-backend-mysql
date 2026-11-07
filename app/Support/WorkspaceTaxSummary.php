<?php

namespace App\Support;

use App\Models\Workspace;

/**
 * The contracts total, tax and grand total of a workspace — the figure the
 * client sees on the payments page. It lives here so the payments page and the
 * client assistant read the same number instead of two copies of the same sum.
 * Tax is worked out per contract (each keeps the % it was approved with) and
 * summed. The keys contracts_total / tax_percentage / tax_amount / grand_total
 * are what GET /workspaces/{id}/payments has always sent; "contracts" is new.
 */
class WorkspaceTaxSummary
{
    public static function for(Workspace $workspace, array $statuses = ['company_approved', 'completed']): array
    {
        $workspace->loadMissing('client');

        $contracts = $workspace->contracts()
            ->whereIn('status', $statuses)
            ->orderBy('id')
            ->get()
            ->each(fn ($c) => $c->setRelation('workspace', $workspace));

        $total = (float) $contracts->sum('value');
        $tax = round((float) $contracts->sum(fn ($c) => $c->tax_amount), 2);
        $percentages = $contracts->map(fn ($c) => $c->tax_percentage)->unique()->values();

        return [
            'contracts_total' => $total,
            // One % when every contract shares it, else the client's current one;
            // the per-contract list below is the exact breakdown.
            'tax_percentage' => $percentages->count() === 1
                ? (float) $percentages->first()
                : (float) ($workspace->client?->client_type === 'business' ? ($workspace->client->tax_percentage ?? 0) : 0),
            'tax_amount' => $tax,
            'grand_total' => $total + $tax,
            'contracts' => $contracts->map(fn ($c) => [
                'id' => $c->id,
                'title' => $c->title,
                'value' => (float) $c->value,
                'tax_percentage' => $c->tax_percentage,
                'tax_amount' => $c->tax_amount,
                'ads_percentage' => $c->ads_percentage,
                'ads_amount' => $c->ads_amount,
                'ads_budget' => $c->ads_budget,
                'total' => $c->total_with_tax,
            ])->values()->all(),
        ];
    }
}
