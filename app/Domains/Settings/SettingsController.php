<?php

namespace App\Domains\Settings;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $settings = SystemSetting::all()->keyBy('key');
        } catch (\Exception $e) {
            $settings = collect();
        }
        return response()->json(['settings' => $settings]);
    }

    public function update(Request $request): JsonResponse
    {
        if (!$request->user()->isSuperAdmin()) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        $validated = $request->validate([
            'key' => 'required|string|in:corporate_tax_percentage',
            'value' => 'required|numeric|min:0|max:100',
        ]);

        $setting = SystemSetting::setValue(
            $validated['key'],
            $validated['value'],
            $validated['key'] === 'corporate_tax_percentage' ? 'نسبة ضريبة الشركات المقدرة (%)' : null
        );

        return response()->json(['setting' => $setting]);
    }

    public function getTaxSummary(Request $request, int $workspaceId): JsonResponse
    {
        $workspace = \App\Models\Workspace::with('client')->findOrFail($workspaceId);
        abort_unless($workspace->canBeAccessedBy($request->user()), 403, 'غير مصرح لك بالوصول إلى مساحة العمل هذه');

        $contractsTotal = $workspace->contracts()
            ->whereIn('status', ['client_approved', 'company_approved', 'completed'])
            ->sum('value');

        $taxPercentage = 0;
        try {
            $taxPercentage = (float) SystemSetting::getValue('corporate_tax_percentage', 0);
        } catch (\Exception $e) {
            $taxPercentage = 0;
        }
        $isBusiness = $workspace->client && $workspace->client->client_type === 'business';

        $taxAmount = $isBusiness ? ($contractsTotal * $taxPercentage / 100) : 0;

        return response()->json([
            'contracts_total' => (float) $contractsTotal,
            'tax_percentage' => $isBusiness ? $taxPercentage : 0,
            'tax_amount' => $taxAmount,
            'grand_total' => $contractsTotal + $taxAmount,
            'client_type' => $workspace->client?->client_type ?? 'business',
        ]);
    }
}
