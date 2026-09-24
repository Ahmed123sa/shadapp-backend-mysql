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
        // 23 Sept 2026 — this used to call $user->workspaces(), but User has
        // no workspaces() relation at all, so every non-super-admin request
        // threw a BadMethodCallException (500). The web dashboard's call to
        // this endpoint swallows errors with a .catch() fallback, so it went
        // unnoticed. Scoped by manager_id instead — the same scope
        // DashboardController::amCounts() uses for the Approvals badge, so
        // this list and that count always agree.
        $workspaceIds = $user->role === 'super_admin'
            ? Workspace::pluck('id')
            : Workspace::where('manager_id', $user->id)->pluck('id');

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
        if ($workspace->isClientArchived()) {
            return response()->json(['message' => 'العميل ده متأرشف، مينفعش تتضاف له طلبات موافقة جديدة. فُك الأرشفة الأول.'], 422);
        }

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

        // 23 Sept 2026 — this used to notify the workspace manager plus every
        // super admin, and never the client — the one person who actually has
        // to respond to the request. Now: the client, plus the workspace
        // manager only when someone else (a super admin) raised the request
        // in their workspace. Super admins no longer get this notification.
        $notifyUsers = collect();
        if ($workspace->client) {
            $notifyUsers->push($workspace->client);
        }
        $manager = $workspace->manager;
        if ($manager && $manager->id !== $request->user()->id && $manager->isActive()) {
            $notifyUsers->push($manager);
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

        // This method is staff-only: ApprovalPolicy::respond() (checked by
        // RespondApprovalRequest::authorize() before this method ever runs)
        // rejects any principal that isn't a User, so a Client or SubUser
        // can never reach here. The branch below is defensive/unreachable
        // in practice — the real client/sub-user approval flow is
        // ChatController::respond(), where can_respond_approvals is
        // enforced via the subuser.can route middleware. See
        // SUBUSER_PLAN.md §2.
        if ($user instanceof \App\Models\SubUser) {
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
