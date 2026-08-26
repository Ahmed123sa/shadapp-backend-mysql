<?php

namespace App\Domains\Workspace;

use App\Models\Meeting;
use App\Models\Payment;
use App\Models\Workspace;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class WorkspaceController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Workspace::class);

        $request->validate(['client_id' => 'required|exists:clients,id']);

        $workspace = Workspace::create([
            'client_id' => $request->client_id,
            'manager_id' => $request->user()->id,
        ]);

        AuditLog::create([
            'auditable_type' => Workspace::class,
            'auditable_id' => $workspace->id,
            'user_id' => $request->user()->id,
            'action' => 'workspace.created',
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['workspace' => $workspace], 201);
    }

    public function show(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('view', $workspace);

        $workspace->load([
            'client', 'manager', 'contracts.clauses', 'contracts.requiredDocuments', 'payments', 'approvals.certificate',
            'meetings', 'files', 'chatMessages.sender',
        ]);

        $nextMeeting = Meeting::where('workspace_id', $workspace->id)
            ->where('status', 'scheduled')
            ->where('scheduled_at', '>', now())
            ->orderBy('scheduled_at')
            ->first();

        $nextPayment = Payment::where('workspace_id', $workspace->id)
            ->where('status', 'scheduled')
            ->where(function ($q) {
                $q->where(function ($q2) {
                    $q2->whereNotNull('due_date')
                        ->where('due_date', '<=', now()->addDay()->toDateString());
                })->orWhereNull('due_date');
            })
            ->orderBy('due_date')
            ->first();

        return response()->json(['workspace' => $workspace, 'nextMeeting' => $nextMeeting, 'nextPayment' => $nextPayment]);
    }

    public function activate(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('activate', $workspace);

        $workspace->update(['status' => 'active', 'activated_at' => now()]);

        AuditLog::create([
            'auditable_type' => Workspace::class,
            'auditable_id' => $workspace->id,
            'user_id' => $request->user()->id,
            'action' => 'workspace.activated',
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['workspace' => $workspace->fresh()]);
    }
}
