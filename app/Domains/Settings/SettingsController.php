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
            'key' => 'required|string|in:corporate_tax_percentage,show_contract_dates,managers_can_review_files',
            'value' => 'required',
        ]);

        $description = match ($validated['key']) {
            'corporate_tax_percentage' => 'نسبة ضريبة الشركات (%)',
            'show_contract_dates' => 'إظهار تواريخ بداية ونهاية العقد',
            'managers_can_review_files' => 'السماح للمدير بالموافقة على ملفات عملائه',
            default => null,
        };

        $setting = SystemSetting::setValue(
            $validated['key'],
            $validated['value'],
            $description
        );

        return response()->json(['setting' => $setting]);
    }

    public function getTaxSummary(Request $request, int $workspaceId): JsonResponse
    {
        $workspace = \App\Models\Workspace::with('client')->findOrFail($workspaceId);
        abort_unless($workspace->canBeAccessedBy($request->user()), 403, 'غير مصرح لك بالوصول إلى مساحة العمل هذه');

        $summary = \App\Support\WorkspaceTaxSummary::for($workspace, ['client_approved', 'company_approved', 'completed']);

        return response()->json($summary + [
            'client_type' => $workspace->client?->client_type ?? 'business',
        ]);
    }
}