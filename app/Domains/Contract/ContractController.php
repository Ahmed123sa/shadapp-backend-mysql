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
        $contracts = Contract::with('workspace.client')
            ->when($user->isAccountManager(), fn($q) => $q->whereHas('workspace', fn($q) => $q->where('manager_id', $user->id)))
            ->latest()
            ->paginate(30);

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

        $contract->update([
            'status' => $status,
            'client_signed_at' => $request->action === 'approved' ? now() : null,
            // Snapshot the signature as it exists right now, at the moment of
            // approval — not whatever ends up on the client's profile later.
            // See ContractPdfService for how this is used when rendering.
            'client_signature_data' => $request->action === 'approved' ? $request->user()->signature_data : null,
            'edit_reason' => $request->action === 'edit_requested' ? ($request->reason ?? null) : null,
        ]);

        AuditLog::create([
            'auditable_type' => Contract::class,
            'auditable_id' => $contract->id,
            'client_id' => $request->user()->id,
            'action' => 'contract.client_' . $request->action,
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
