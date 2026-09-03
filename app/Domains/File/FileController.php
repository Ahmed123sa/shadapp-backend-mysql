<?php

namespace App\Domains\File;

use App\Models\FileEntry;
use App\Models\DocumentDefinition;
use App\Models\Workspace;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use App\Models\Payment;
use App\Models\ContractRequiredDocument;
use App\Support\UploadRules;
use Illuminate\Support\Facades\Storage;

class FileController extends Controller
{
    public function allFiles(Request $request): JsonResponse
    {
        $this->authorize('viewAny', FileEntry::class);

        $user = $request->user();
        $files = FileEntry::with('workspace.client', 'uploadedBy')
            ->when($user->isAccountManager(), fn($q) => $q->whereHas('workspace', fn($q) => $q->where('manager_id', $user->id)))
            ->latest()
            ->paginate(30);

        return response()->json(['files' => $files]);
    }

    public function index(Workspace $workspace): JsonResponse
    {
        $files = $workspace->files()->with('documentDefinition', 'reviewer')->latest()->get();
        $definitions = $workspace->documentDefinitions()->orderBy('sort_order')->get();

        $files->each(function ($file) {
            $file->tag = $this->computeTag($file);
        });

        $payments = $workspace->payments()
            ->whereNotNull('proof_file_url')
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($payment) {
                $urls = is_array($payment->proof_file_url) ? $payment->proof_file_url : [];
                return [
                    'id' => 'payment-' . $payment->id,
                    'payment_id' => $payment->id,
                    'name' => 'إثبات دفع #' . $payment->id,
                    'tag' => 'إثبات الدفع',
                    'file_url' => $urls[0] ?? null,
                    'file_urls' => $urls,
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                    'status' => $payment->status,
                    'created_at' => $payment->created_at,
                    'source' => 'payment',
                ];
            });

        return response()->json(['files' => $files, 'definitions' => $definitions, 'paymentFiles' => $payments]);
    }

    private function computeTag(FileEntry $file): string
    {
        if ($file->documentDefinition) {
            return $file->documentDefinition->name;
        }
        if ($file->contractRequiredDocument) {
            return 'مستند مطلوب';
        }
        if ($file->contract_id) {
            return 'مرفق عقد';
        }
        if ($file->approval_id) {
            return 'مرفق موافقة';
        }
        return 'عام';
    }

    public function upload(Request $request, Workspace $workspace): JsonResponse
    {
        $request->validate([
            'file' => UploadRules::document(required: true),
            'name' => 'nullable|string|max:255',
            'document_definition_id' => 'nullable|exists:document_definitions,id',
            'contract_id' => 'nullable|exists:contracts,id',
            'contract_required_document_id' => 'nullable|exists:contract_required_documents,id',
        ]);

        $path = $request->file('file')->store('workspace-' . $workspace->id, 'public');

        $file = $workspace->files()->create([
            'document_definition_id' => $request->document_definition_id,
            'contract_id' => $request->contract_id,
            'contract_required_document_id' => $request->contract_required_document_id,
            'uploaded_by_type' => get_class($request->user()),
            'uploaded_by_id' => $request->user()->id,
            'file_url' => Storage::url($path),
            'name' => $request->name ?? $request->file('file')->getClientOriginalName(),
            'type' => $request->file('file')->getMimeType(),
            'size' => $request->file('file')->getSize(),
            'status' => $request->user() instanceof \App\Models\Client ? 'pending' : 'approved',
        ]);

        $auditData = [
            'auditable_type' => FileEntry::class,
            'auditable_id' => $file->id,
            'action' => 'file.uploaded',
            'ip_address' => $request->ip(),
        ];
        if ($request->user() instanceof \App\Models\User) {
            $auditData['user_id'] = $request->user()->id;
        }
        AuditLog::create($auditData);

        return response()->json(['file' => $file->load('documentDefinition')], 201);
    }

    public function review(Request $request, FileEntry $file): JsonResponse
    {
        if (!$request->user()->isSuperAdmin()) {
            abort(403, 'Only Super Admin can review documents');
        }

        $request->validate([
            'action' => 'required|in:approved,rejected',
            'rejection_reason' => 'required_if:action,rejected|nullable|string',
        ]);

        $file->update([
            'status' => $request->action,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'rejection_reason' => $request->rejection_reason,
        ]);

        $auditData = [
            'auditable_type' => FileEntry::class,
            'auditable_id' => $file->id,
            'action' => 'file.' . $request->action,
            'ip_address' => $request->ip(),
        ];
        if ($request->user() instanceof \App\Models\User) {
            $auditData['user_id'] = $request->user()->id;
        }
        AuditLog::create($auditData);

        return response()->json(['file' => $file->fresh()->load('documentDefinition', 'reviewer')]);
    }

    public function storeDefinition(Request $request, Workspace $workspace): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_required' => 'boolean',
        ]);

        $definition = $workspace->documentDefinitions()->create([
            'name' => $request->name,
            'description' => $request->description,
            'is_required' => $request->is_required ?? true,
            'sort_order' => $workspace->documentDefinitions()->count(),
        ]);

        return response()->json(['definition' => $definition], 201);
    }

    public function destroyDefinition(Request $request, Workspace $workspace, DocumentDefinition $documentDefinition): JsonResponse
    {
        $documentDefinition->delete();
        return response()->json(['message' => 'تم حذف تعريف المستند']);
    }

    public function destroy(Request $request, Workspace $workspace, FileEntry $file): JsonResponse
    {
        if ($file->workspace_id !== $workspace->id) {
            abort(404);
        }

        if ($file->uploaded_by_type !== get_class($request->user()) || $file->uploaded_by_id !== $request->user()->id) {
            abort(403, 'غير مصرح لك بحذف هذا الملف');
        }

        if ($file->status === 'approved') {
            abort(422, 'لا يمكن حذف ملف تمت الموافقة عليه');
        }

        // getRawOriginal bypasses the file_url accessor (which signs the value
        // for display) to get back the plain, permanently-shaped stored path.
        $rawUrl = $file->getRawOriginal('file_url');
        if ($rawUrl && Storage::disk('public')->exists(str_replace('/storage/', '', $rawUrl))) {
            Storage::disk('public')->delete(str_replace('/storage/', '', $rawUrl));
        }

        $file->delete();

        return response()->json(['message' => 'تم حذف الملف']);
    }
}
