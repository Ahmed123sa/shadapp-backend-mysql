<?php

namespace App\Domains\DataExport;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateDataExport;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\DataExport;
use App\Models\User;
use App\Policies\DataExportPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Mirrors app/Domains/DataExport/DataExportController.php from
 * shadapp-backend (Postgres) verbatim — no database-specific code involved.
 *
 * DATA_SAFETY_PLAN.md §3 — scoped, authorized data export requests.
 *
 * The three "may this user request this scope" checks (requestSystem /
 * requestManager / requestClient) are called on DataExportPolicy directly
 * rather than through $this->authorize(), because none of them has a single
 * natural model instance to authorize against — Laravel's Gate resolves
 * which Policy class applies from the *class of the argument passed in*, and
 * the argument here is either nothing (system), a bare manager id (manager),
 * or a Client (whose class already has its own, unrelated ClientPolicy
 * registered). Calling the Policy's methods directly sidesteps that
 * resolution entirely and is exactly as testable. The `view`/download-link
 * check below, by contrast, takes a real DataExport instance and goes
 * through $this->authorize() normally.
 */
class DataExportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $exports = DataExport::query()
            ->when(! $user->isSuperAdmin(), fn ($q) => $q->where('requested_by_type', User::class)->where('requested_by_id', $user->id))
            ->latest()
            ->paginate(20);

        // getDownloadUrlAttribute() isn't in DataExport's $appends (it mints
        // a fresh 15-minute signed URL every time it's touched, so it must
        // never end up cached/serialized outside a request that's actually
        // about to use it) — appended explicitly here, once, on the rows
        // this specific request is authorized to see (already filtered
        // above), rather than globally on the model.
        $exports->getCollection()->transform(fn (DataExport $export) => $export->append('download_url'));

        return response()->json(['exports' => $exports]);
    }

    public function store(Request $request, DataExportPolicy $policy): JsonResponse
    {
        $request->validate([
            'scope' => 'required|in:'.implode(',', [DataExport::SCOPE_SYSTEM, DataExport::SCOPE_MANAGER, DataExport::SCOPE_CLIENT]),
            'scope_id' => 'required_unless:scope,'.DataExport::SCOPE_SYSTEM.'|integer',
        ]);

        $scope = $request->string('scope')->toString();
        $scopeId = $request->input('scope_id');
        $user = $request->user();

        switch ($scope) {
            case DataExport::SCOPE_SYSTEM:
                if (! $policy->requestSystem($user)) {
                    abort(403);
                }
                $scopeId = null;
                break;

            case DataExport::SCOPE_MANAGER:
                $targetManager = User::find($scopeId);
                if (! $targetManager || ! $targetManager->isAccountManager()) {
                    return response()->json([
                        'message' => 'المستخدم المختار مش مدير حساب.',
                        'errors' => ['scope_id' => ['المستخدم المختار مش مدير حساب.']],
                    ], 422);
                }
                if (! $policy->requestManager($user, $targetManager->id)) {
                    abort(403);
                }
                break;

            case DataExport::SCOPE_CLIENT:
                $targetClient = Client::find($scopeId);
                if (! $targetClient) {
                    return response()->json([
                        'message' => 'العميل غير موجود.',
                        'errors' => ['scope_id' => ['العميل غير موجود.']],
                    ], 422);
                }
                if (! $policy->requestClient($user, $targetClient)) {
                    abort(403);
                }
                break;
        }

        $export = new DataExport([
            'scope' => $scope,
            'scope_id' => $scopeId,
            'status' => DataExport::STATUS_PENDING,
        ]);
        $export->requestedBy()->associate($user);
        $export->save();

        AuditLog::create([
            'auditable_type' => DataExport::class,
            'auditable_id' => $export->id,
            'user_id' => $user->id,
            'client_id' => $scope === DataExport::SCOPE_CLIENT ? $scopeId : null,
            'action' => 'data_export.requested',
            'metadata' => ['scope' => $scope, 'scope_id' => $scopeId],
            'ip_address' => $request->ip(),
        ]);

        GenerateDataExport::dispatch($export);

        return response()->json(['export' => $export], 202);
    }

    /**
     * The download route itself (routes/web.php, 'exports.download') is
     * deliberately unauthenticated — it only ever carries a short-lived
     * signed URL, the same pattern App\Support\FileUrl already uses for
     * /files/*. The normal API authorization already ran once, when the
     * signed link was minted (see DataExport::getDownloadUrlAttribute,
     * only ever surfaced on rows index() already filtered to what $user is
     * allowed to see). What still needs checking *here*, at the moment the
     * link is actually used, is not "is this the right user" (a bearer
     * token has no meaning on a plain browser navigation to a signed URL)
     * but "is this export still valid" — ready, not expired, file still on
     * disk. That's the "policy check on download" DATA_SAFETY_PLAN.md
     * §3.5.3 asks for; the 'signed' middleware in front of this route
     * covers the two required tests (no signature, or an expired one) on
     * its own.
     */
    public function download(DataExport $dataExport)
    {
        if (! $dataExport->isReady() || $dataExport->isExpired() || ! $dataExport->file_path) {
            abort(403);
        }

        if (! Storage::disk('local')->exists($dataExport->file_path)) {
            abort(403);
        }

        return Storage::disk('local')->download($dataExport->file_path, basename($dataExport->file_path));
    }
}
