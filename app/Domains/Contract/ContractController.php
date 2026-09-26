<?php

namespace App\Domains\Contract;

use App\Models\Contract;
use App\Models\ContractClause;
use App\Models\ContractClauseTemplate;
use App\Models\Workspace;
use App\Models\AuditLog;
use App\Models\User;
use App\Events\ContractSent;
use App\Events\ContractClientApproved;
use App\Events\ContractCompanyApproved;
use App\Events\ContractCompleted;
use App\Events\ContractStatusChanged;
use App\Events\WorkspaceStatusChanged;
use App\Http\Requests\StoreContractRequest;
use App\Http\Requests\UpdateContractRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class ContractController extends Controller
{
    public function allContracts(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Contract::class);

        $user = $request->user();
        // 24 Sept 2026 (server-side-stats-plan.md, Stage 4, W10) — was a
        // hardcoded paginate(30) that silently ignored any per_page the
        // caller sent. Clamped to 100 (unlike /all-payments' uncapped
        // per_page) since this eager-loads workspace.client per row; default
        // stays 30 so a caller that never sends per_page sees no change.
        $perPage = max(1, min((int) $request->input('per_page', 30), 100));
        $contracts = Contract::with('workspace.client')
            ->when($user->isAccountManager(), fn($q) => $q->whereHas('workspace', fn($q) => $q->where('manager_id', $user->id)))
            ->latest()
            ->paginate($perPage);

        $this->ensureFreshPdfs($contracts->getCollection());

        return response()->json(['contracts' => $contracts]);
    }

    public function templates(Request $request): JsonResponse
    {
        if ($request->boolean('all') && !$request->user()->isSuperAdmin()) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        $templates = ContractClauseTemplate::when(!$request->boolean('all'), fn($q) => $q->where('is_active', true))
            ->orderBy('sort_order')
            ->get();

        return response()->json(['templates' => $templates]);
    }

    public function storeTemplate(Request $request): JsonResponse
    {
        if (!$request->user()->isSuperAdmin()) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        $request->validate([
            'type' => 'required|in:fixed,optional',
            'content' => 'required|string',
            'category' => 'nullable|string|max:100',
            'is_active' => 'boolean',
        ]);

        $template = ContractClauseTemplate::create([
            'type' => $request->type,
            'content' => $request->content,
            'category' => $request->category,
            'is_active' => $request->boolean('is_active', true),
            'sort_order' => (int) ContractClauseTemplate::max('sort_order') + 1,
        ]);

        AuditLog::create([
            'auditable_type' => ContractClauseTemplate::class,
            'auditable_id' => $template->id,
            'user_id' => $request->user()->id,
            'action' => 'clause_template.created',
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['template' => $template], 201);
    }

    public function updateTemplate(Request $request, ContractClauseTemplate $template): JsonResponse
    {
        if (!$request->user()->isSuperAdmin()) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        $request->validate([
            'type' => 'sometimes|in:fixed,optional',
            'content' => 'sometimes|string',
            'category' => 'nullable|string|max:100',
            'is_active' => 'boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $template->update($request->only(['type', 'content', 'category', 'is_active', 'sort_order']));

        AuditLog::create([
            'auditable_type' => ContractClauseTemplate::class,
            'auditable_id' => $template->id,
            'user_id' => $request->user()->id,
            'action' => 'clause_template.updated',
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['template' => $template->fresh()]);
    }

    public function destroyTemplate(Request $request, ContractClauseTemplate $template): JsonResponse
    {
        if (!$request->user()->isSuperAdmin()) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        $template->delete();

        AuditLog::create([
            'auditable_type' => ContractClauseTemplate::class,
            'auditable_id' => $template->id,
            'user_id' => $request->user()->id,
            'action' => 'clause_template.deleted',
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['message' => 'تم حذف البند']);
    }

    public function reorderTemplates(Request $request): JsonResponse
    {
        if (!$request->user()->isSuperAdmin()) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        $request->validate([
            'ordered_ids' => 'required|array',
            'ordered_ids.*' => 'integer',
        ]);

        foreach (array_values($request->ordered_ids) as $index => $id) {
            ContractClauseTemplate::where('id', $id)->update(['sort_order' => $index]);
        }

        return response()->json(['templates' => ContractClauseTemplate::orderBy('sort_order')->get()]);
    }

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('viewAny', Contract::class);

        $contracts = $workspace->contracts()->with('clauses', 'requiredDocuments.files')->latest()->paginate(30);
        $this->ensureFreshPdfs($contracts->getCollection());

        return response()->json(['contracts' => $contracts]);
    }

    private function ensureFreshPdfs($contracts): void
    {
        $service = app(\App\Services\ContractPdfService::class);
        foreach ($contracts as $contract) {
            $service->ensureFreshPdf($contract);
        }
    }

    public function store(StoreContractRequest $request, Workspace $workspace): JsonResponse
    {
        if ($workspace->isClientArchived()) {
            return response()->json(['message' => 'العميل ده متأرشف، مينفعش تتضاف له عقود جديدة. فُك الأرشفة الأول.'], 422);
        }

        $contract = $workspace->contracts()->create([
            'title' => $request->title,
            'contract_type' => $request->contract_type ?? 'main',
            'value' => $request->value ?? 0,
            'currency' => $request->currency ?? 'SAR',
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'status' => 'draft',
            'created_by' => $request->user()->id,
        ]);

        $requestedContents = collect($request->clauses ?? [])->pluck('content')->map(fn($c) => trim((string) $c))->all();

        $sortOrder = 0;
        $fixedTemplates = ContractClauseTemplate::where('type', 'fixed')->where('is_active', true)->orderBy('sort_order')->get();
        foreach ($fixedTemplates as $template) {
            if (in_array(trim($template->content), $requestedContents, true)) {
                continue;
            }
            $contract->clauses()->create([
                'content' => $template->content,
                'type' => 'fixed',
                'sort_order' => $sortOrder++,
            ]);
        }

        if ($request->clauses) {
            foreach ($request->clauses as $clause) {
                $contract->clauses()->create([
                    'content' => $clause['content'],
                    'type' => $clause['type'] ?? 'custom',
                    'sort_order' => $sortOrder++,
                ]);
            }
        }

        if ($request->required_documents) {
            foreach ($request->required_documents as $i => $doc) {
                $contract->requiredDocuments()->create([
                    'name' => $doc['name'],
                    'description' => $doc['description'] ?? null,
                    'is_required' => true,
                    'sort_order' => $i,
                ]);
            }
        }

        AuditLog::create([
            'auditable_type' => Contract::class,
            'auditable_id' => $contract->id,
            'user_id' => $request->user()->id,
            'action' => 'contract.created',
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['contract' => $contract->load('clauses', 'requiredDocuments')], 201);
    }

    public function show(Request $request, Contract $contract): JsonResponse
    {
        $this->authorize('view', $contract);

        app(\App\Services\ContractPdfService::class)->ensureFreshPdf($contract);

        return response()->json(['contract' => $contract->load('clauses', 'workspace', 'requiredDocuments')]);
    }

    public function update(UpdateContractRequest $request, Contract $contract): JsonResponse
    {

        $contract->update($request->only(['title', 'value', 'currency', 'start_date', 'end_date', 'contract_type']));

        if ($request->has('clauses')) {
            $contract->clauses()->delete();
            foreach ($request->clauses as $i => $clause) {
                $contract->clauses()->create([
                    'content' => $clause['content'],
                    'type' => $clause['type'] ?? 'custom',
                    'sort_order' => $i,
                ]);
            }
        }

        if ($request->has('required_documents')) {
            $contract->requiredDocuments()->delete();
            foreach ($request->required_documents as $i => $doc) {
                $contract->requiredDocuments()->create([
                    'name' => $doc['name'],
                    'is_required' => true,
                    'sort_order' => $i,
                ]);
            }
        }

        AuditLog::create([
            'auditable_type' => Contract::class,
            'auditable_id' => $contract->id,
            'user_id' => $request->user()->id,
            'action' => 'contract.updated',
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['contract' => $contract->fresh()->load('clauses', 'requiredDocuments')]);
    }

    public function destroy(Request $request, Contract $contract): JsonResponse
    {
        $this->authorize('delete', $contract);

        $contract->delete();

        AuditLog::create([
            'auditable_type' => Contract::class,
            'auditable_id' => $contract->id,
            'user_id' => $request->user()->id,
            'action' => 'contract.deleted',
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['message' => 'تم حذف العقد']);
    }

    public function send(Request $request, Contract $contract): JsonResponse
    {
        $this->authorize('send', $contract);

        $contract->update(['status' => 'sent']);

        event(new ContractSent($contract));
        ContractStatusChanged::dispatch($contract);

        AuditLog::create([
            'auditable_type' => Contract::class,
            'auditable_id' => $contract->id,
            'user_id' => $request->user()->id,
            'action' => 'contract.sent',
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['contract' => $contract->fresh()->load('clauses')]);
    }

    public function clientAction(Request $request, Contract $contract): JsonResponse
    {
        abort_unless($contract->workspace->canBeAccessedBy($request->user()), 403, 'غير مصرح لك بالوصول إلى مساحة العمل هذه');

        $request->validate(['action' => 'required|in:approved,edit_requested']);

        $status = $request->action === 'edit_requested' ? 'edit_requested' : 'client_approved';

        // This endpoint is reachable by a Client, a SubUser, or a User
        // (staff) proxying the client's approval — e.g. an AM entering it
        // after getting verbal sign-off over the phone; see
        // RealWorldScenarioTest's client-action steps, which call this as
        // the AM, not the client. Whoever calls it, the signature that
        // belongs on the contract is always the client's own: a sub-user has
        // none of its own (sub_users has no signature_data column, so
        // $signer->signature_data would silently evaluate to null — Eloquent
        // doesn't raise on a missing attribute), and staff's own saved
        // signature (used for company_signature_data elsewhere) is not the
        // client's. Same pattern used in ChatController::respond().
        $signer = $request->user();
        $signature = match (true) {
            $signer instanceof \App\Models\SubUser => $signer->client?->signature_data,
            $signer instanceof \App\Models\Client => $signer->signature_data,
            default => $contract->workspace->client?->signature_data,
        };

        // client-signature-plan.md ن2 — this used to accept 'approved' with
        // no saved signature at all, silently closing the contract with
        // client_signature_data left null. The web dashboard happens to
        // block the client from reaching this screen without a signature
        // first, but the mobile app doesn't (see ن3), so this was reachable
        // in practice. 'edit_requested' never needed a signature and still
        // doesn't. This check sits after the workspace-access check above so
        // an unauthorized caller still gets 403, not 422.
        if ($request->action === 'approved' && empty($signature)) {
            return response()->json([
                'message' => 'لازم تحفظ توقيعك الأول قبل ما توافق على العقد.',
                'code' => 'signature_required',
            ], 422);
        }

        $contract->update([
            'status' => $status,
            'client_signed_at' => $request->action === 'approved' ? now() : null,
            // Snapshot the signature as it exists right now, at the moment of
            // approval — not whatever ends up on the client's profile later.
            // See ContractPdfService for how this is used when rendering.
            'client_signature_data' => $request->action === 'approved' ? $signature : null,
            'edit_reason' => $request->action === 'edit_requested' ? ($request->reason ?? null) : null,
        ]);

        // `client_id` here is the contract's owning client — not
        // "$signer->id" as it used to read. This route is reachable by a
        // User (staff), a Client, or a SubUser (Workspace::canBeAccessedBy
        // allows all three), but audit_logs.client_id is a foreign key into
        // `clients`. Writing the signer's own id unconditionally worked by
        // coincidence when the signer was the Client, but threw a 1452 FK
        // violation whenever a sub-user approved a contract — a real,
        // tested path (see "a sub user approving a contract stores the
        // parent client's signature"). Same class of bug as
        // SubUserController's audit_logs.user_id fix; 'acted_by' keeps
        // track of which of the three actually signed.
        AuditLog::create([
            'auditable_type' => Contract::class,
            'auditable_id' => $contract->id,
            'client_id' => $contract->workspace->client_id,
            'user_id' => $signer instanceof User ? $signer->id : null,
            'action' => 'contract.client_' . $request->action,
            'metadata' => [
                'acted_by' => match (true) {
                    $signer instanceof \App\Models\SubUser => 'sub_user:' . $signer->id,
                    $signer instanceof \App\Models\Client => 'client',
                    default => 'staff',
                },
            ],
            'ip_address' => $request->ip(),
        ]);

        if ($request->action === 'approved') {
            event(new ContractClientApproved($contract));
        } elseif ($request->action === 'edit_requested') {
            $manager = $contract->workspace?->manager;
            if ($manager) {
                $manager->notify(new \App\Notifications\ContractEditRequestedNotification($contract));
            }
        }
        ContractStatusChanged::dispatch($contract);

        return response()->json(['contract' => $contract->fresh()]);
    }

    public function companyApprove(Request $request, Contract $contract): JsonResponse
    {
        $this->authorize('companyApprove', $contract);

        $request->validate([
            'signature' => 'nullable|string',
        ]);

        $contract->update([
            'status' => 'company_approved',
            'company_signed_at' => now(),
            'company_signature_data' => $request->signature ?? $request->user()->signature_data ?? $request->user()->name,
            'company_signature_type' => $request->signature && (str_starts_with($request->signature, '/storage/') || str_starts_with($request->signature, 'http')) ? 'image' : 'text',
        ]);

        $workspace = $contract->workspace->fresh();

        // Activate workspace if already fully paid
        if ($workspace->payments()->where('status', 'approved')->exists()) {
            $workspace->update(['status' => 'active', 'activated_at' => now()]);
            WorkspaceStatusChanged::dispatch($workspace->fresh());
        }

        event(new ContractCompanyApproved($contract));
        ContractStatusChanged::dispatch($contract);

        AuditLog::create([
            'auditable_type' => Contract::class,
            'auditable_id' => $contract->id,
            'user_id' => $request->user()->id,
            'action' => 'contract.company_approved',
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['contract' => $contract->fresh()->load('workspace.payments')]);
    }

    public function complete(Request $request, Contract $contract): JsonResponse
    {
        $this->authorize('complete', $contract);

        $contract->update(['status' => 'completed']);

        $workspace = $contract->workspace;
        if ($workspace->payments()->where('status', 'approved')->exists()) {
            $workspace->update(['status' => 'active', 'activated_at' => now()]);
            WorkspaceStatusChanged::dispatch($workspace->fresh());
        }

        event(new ContractCompleted($contract));
        ContractStatusChanged::dispatch($contract);

        AuditLog::create([
            'auditable_type' => Contract::class,
            'auditable_id' => $contract->id,
            'user_id' => $request->user()->id,
            'action' => 'contract.completed',
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['contract' => $contract->fresh()]);
    }

    public function requiredDocuments(Request $request, Contract $contract): JsonResponse
    {
        abort_unless($contract->workspace->canBeAccessedBy($request->user()), 403, 'غير مصرح لك بالوصول إلى مساحة العمل هذه');

        $docs = $contract->requiredDocuments()->with('files')->get();
        return response()->json(['required_documents' => $docs]);
    }

    public function files(Request $request, Contract $contract): JsonResponse
    {
        abort_unless($contract->workspace->canBeAccessedBy($request->user()), 403, 'غير مصرح لك بالوصول إلى مساحة العمل هذه');

        $files = $contract->workspace->files()->where('contract_id', $contract->id)
            ->with('documentDefinition', 'reviewer', 'contractRequiredDocument')
            ->latest()->get();
        return response()->json(['files' => $files]);
    }

    public function archive(Request $request, Contract $contract): JsonResponse
    {
        $this->authorize('archive', $contract);

        if ($contract->workspace->payments()->where('status', 'approved')->exists()) {
            return response()->json(['message' => 'لا يمكن أرشفة العقد بعد الموافقة على المدفوعات'], 422);
        }
        $contract->update(['status' => 'archived', 'archived_at' => now()]);

        AuditLog::create([
            'auditable_type' => Contract::class,
            'auditable_id' => $contract->id,
            'user_id' => $request->user()->id,
            'action' => 'contract.archived',
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['contract' => $contract->fresh()]);
    }

    public function addClause(Request $request, Contract $contract): JsonResponse
    {
        $this->authorize('update', $contract);

        $request->validate([
            'content' => 'required|string',
            'type' => 'in:fixed,optional,custom',
        ]);

        $clause = $contract->clauses()->create([
            'content' => $request->content,
            'type' => $request->type ?? 'custom',
            'sort_order' => $contract->clauses()->count(),
        ]);

        return response()->json(['clause' => $clause], 201);
    }

    public function updateClause(Request $request, Contract $contract, ContractClause $clause): JsonResponse
    {
        $this->authorize('update', $contract);
        abort_if($clause->contract_id !== $contract->id, 404);

        $request->validate([
            'content' => 'required|string',
            'type' => 'in:fixed,optional,custom',
            'sort_order' => 'integer|min:0',
        ]);

        $clause->update($request->only(['content', 'type', 'sort_order']));

        return response()->json(['clause' => $clause->fresh()]);
    }

    public function destroyClause(Request $request, Contract $contract, ContractClause $clause): JsonResponse
    {
        $this->authorize('update', $contract);
        abort_if($clause->contract_id !== $contract->id, 404);

        $clause->delete();

        return response()->json(['message' => 'تم حذف البند']);
    }
}
