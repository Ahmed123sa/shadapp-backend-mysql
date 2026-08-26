<?php

namespace App\Domains\Approval;

use App\Models\Approval;
use App\Models\ApprovalCertificate;
use App\Models\Workspace;
use App\Models\AuditLog;
use App\Events\ApprovalResponded;
use App\Http\Requests\StoreApprovalRequest;
use App\Http\Requests\RespondApprovalRequest;
use App\Models\User;
use App\Notifications\ApprovalRequestedNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\Controller;
use Illuminate\Support\Str;
use App\Services\ApprovalPdfService;

class ApprovalController extends Controller
{
    public function pending(Request $request): JsonResponse
    {
        $user = $request->user();
        $workspaceIds = $user->role === 'super_admin'
            ? Workspace::pluck('id')
            : $user->workspaces()->pluck('id');

        $approvals = Approval::whereIn('workspace_id', $workspaceIds)
            ->where('status', 'pending')
            ->with(['workspace.client', 'requester'])
            ->latest()
            ->get();

        return response()->json(['approvals' => $approvals]);
    }

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('viewAny', Approval::class);

        $user = $request->user();
        return response()->json(['approvals' => $workspace->approvals()
            ->with('certificate', 'requester', 'files', 'chatMessage')
            ->latest()
            ->paginate(30)]);
    }

    public function store(StoreApprovalRequest $request, Workspace $workspace): JsonResponse
    {

        $approval = $workspace->approvals()->create([
            'title' => $request->title,
            'description' => $request->description,
            'approvable_type' => 'workspace',
            'approvable_id' => $workspace->id,
            'reference_no' => 'APP-' . strtoupper(Str::random(10)),
            'requested_by' => $request->user()->id,
            'status' => 'pending',
        ]);

        // Save uploaded files
        if ($request->hasFile('files')) {
            foreach ($request->file('files') as $file) {
                $path = $file->store('approval-attachments', 'public');
                $approval->files()->create([
                    'workspace_id' => $workspace->id,
                    'uploaded_by_type' => get_class($request->user()),
                    'uploaded_by_id' => $request->user()->id,
                    'file_url' => Storage::url($path),
                    'name' => $file->getClientOriginalName(),
                    'type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                    'status' => 'pending',
                ]);
            }
        }

        // Create a chat message for this approval
        $msg = $workspace->chatMessages()->create([
            'sender_type' => get_class($request->user()),
            'sender_id' => $request->user()->id,
            'message' => '📋 طلب موافقة: ' . $request->title . ($request->description ? "\n" . $request->description : ''),
            'type' => 'text',
            'requires_action' => true,
            'approval_id' => $approval->id,
            'action_taken' => false,
        ]);

        AuditLog::create([
            'auditable_type' => Approval::class,
            'auditable_id' => $approval->id,
            'user_id' => $request->user()->id,
            'action' => 'approval.created',
            'metadata' => ['reference_no' => $approval->reference_no],
            'ip_address' => $request->ip(),
        ]);

        $manager = $workspace->manager;
        $admins = User::where('role', User::ROLE_SUPER_ADMIN)->get();
        $notifyUsers = collect();
        if ($manager) {
            $notifyUsers->push($manager);
        }
        foreach ($admins as $admin) {
            if ($admin->id !== $request->user()->id) {
                $notifyUsers->push($admin);
            }
        }
        foreach ($notifyUsers as $user) {
            try {
                $user->notify(new ApprovalRequestedNotification($approval));
            } catch (\Exception $e) {
                Log::warning('Failed to send approval requested notification: ' . $e->getMessage());
            }
        }

        return response()->json(['approval' => $approval->load('requester', 'files', 'chatMessage')], 201);
    }

    public function show(Request $request, Approval $approval): JsonResponse
    {
        $this->authorize('view', $approval);

        return response()->json(['approval' => $approval->load('certificate', 'requester', 'files', 'chatMessage')]);
    }

    public function respond(RespondApprovalRequest $request, Approval $approval): JsonResponse
    {

        $user = $request->user();

        abort_unless($approval->workspace->canBeAccessedBy($user), 403, 'غير مصرح لك بالوصول إلى مساحة العمل هذه');

        if ($user instanceof \App\Models\SubUser) {
            if (!($user->hasPermission('can_respond_approvals') ?? false)) {
                return response()->json(['message' => 'ليس لديك صلاحية الرد على الطلبات'], 403);
            }
            $signature = $user->client->signature_data ?? null;
        } else {
            $signature = $user instanceof \App\Models\Client ? $user->signature_data : null;
        }

        $approval->update([
            'status' => $request->action === 'approved' ? 'approved' : 'edit_requested',
            'client_action' => $request->action,
            'signature' => $signature,
            'responded_at' => now(),
            'reason' => $request->input('reason'),
        ]);

        // Generate PDF certificate on approval
        $pdfPath = null;
        if ($request->action === 'approved') {
            $pdfPath = app(ApprovalPdfService::class)->generateCertificate($approval);
            $approval->certificate()->create([
                'pdf_url' => $pdfPath,
                'generated_at' => now(),
            ]);
        }

        // Update linked chat message
        $chatMsg = $approval->chatMessage;
        if ($chatMsg) {
            $chatMsg->update([
                'action_taken' => true,
                'action_result' => $request->action,
                'responded_at' => now(),
            ]);
        }

        ApprovalResponded::dispatch($approval);

        AuditLog::create(array_filter([
            'auditable_type' => Approval::class,
            'auditable_id' => $approval->id,
            'client_id' => $user instanceof \App\Models\Client ? $user->id : null,
            'action' => 'approval.' . $request->action,
            'metadata' => ['reference_no' => $approval->reference_no],
            'ip_address' => $request->ip(),
        ]));

        return response()->json(['approval' => $approval->fresh()->load('certificate', 'files', 'chatMessage', 'requester')]);
    }
}
