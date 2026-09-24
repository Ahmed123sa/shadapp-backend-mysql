<?php

namespace App\Domains\Meeting;

use App\Models\Meeting;
use App\Models\User;
use App\Models\Workspace;
use App\Models\AuditLog;
use App\Events\MeetingCreated;
use App\Http\Requests\StoreMeetingRequest;
use App\Services\ZoomService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class MeetingController extends Controller
{
    public function allMeetings(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Meeting::class);

        $user = $request->user();
        // 24 Sept 2026 (server-side-stats-plan.md, Stage 4, W10) — see the
        // matching comment on ContractController::allContracts.
        $perPage = max(1, min((int) $request->input('per_page', 30), 100));
        $meetings = Meeting::with('workspace.client', 'contract', 'approval')
            ->when($user->isAccountManager(), fn($q) => $q->whereHas('workspace', fn($q) => $q->where('manager_id', $user->id)))
            ->latest()
            ->paginate($perPage);

        return response()->json(['meetings' => $meetings]);
    }

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('viewAny', Meeting::class);

        return response()->json(['meetings' => $workspace->meetings()->with('contract', 'approval')->latest()->paginate(30)]);
    }

    public function store(StoreMeetingRequest $request, Workspace $workspace): JsonResponse
    {
        // Meetings are staff-scheduled only, but this route has no {client}
        // segment for ScopeWorkspace's canBeAccessedBy() check to reject a
        // Client/SubUser outright — it lets all three actor types through
        // when they belong to this workspace. Client/SubUser have no
        // isSuperAdmin() method, so calling it on them directly (as this
        // used to) was a fatal error instead of a clean 403.
        $actor = $request->user();
        if (!$actor instanceof User || (!$actor->isSuperAdmin() && $workspace->manager_id !== $actor->id)) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        if ($workspace->isClientArchived()) {
            return response()->json(['message' => 'العميل ده متأرشف، مينفعش تتضاف له اجتماعات جديدة. فُك الأرشفة الأول.'], 422);
        }

        $meetingData = [
            'workspace_id' => $workspace->id,
            'title' => $request->title,
            'scheduled_at' => $request->scheduled_at,
            'duration_minutes' => $request->duration_minutes ?? 30,
            'contract_id' => $request->contract_id,
            'approval_id' => $request->approval_id,
            'notes' => $request->notes,
            'status' => 'scheduled',
            'created_by' => $request->user()->id,
        ];

        if (ZoomService::isConfigured()) {
            try {
                $zoom = app(ZoomService::class);
                // Zoom's documented GMT format, rather than whatever offset
                // format the app sent (see Meeting::setScheduledAtAttribute).
                $zoomMeeting = $zoom->createMeeting($request->title, self::zoomTime($request->scheduled_at), $request->duration_minutes ?? 30);
                $meetingData['zoom_meeting_id'] = $zoomMeeting['id'] ?? null;
                $meetingData['link'] = $zoomMeeting['join_url'] ?? null;
                $meetingData['passcode'] = $zoomMeeting['password'] ?? null;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $meeting = Meeting::create($meetingData);

        MeetingCreated::dispatch($meeting);

        AuditLog::create([
            'auditable_type' => Meeting::class,
            'auditable_id' => $meeting->id,
            'user_id' => $request->user()->id,
            'action' => 'meeting.created',
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['meeting' => $meeting], 201);
    }

    // 23 Sept 2026 — both routes are /workspaces/{workspace}/meetings/{meeting},
    // but these two methods only declared $meeting. Laravel passes route
    // parameters positionally once one of them is already resolved, so
    // $meeting received the raw workspace id string and every call 500'd
    // with a TypeError (mobile's edit-meeting sheet uses PUT). Declaring
    // $workspace also lets ScopeWorkspace run its tenant/child-resource
    // check, which it silently skipped while {workspace} was an unbound
    // string.
    public function update(Request $request, Workspace $workspace, Meeting $meeting): JsonResponse
    {
        $this->authorize('update', $meeting);

        $meeting->update($request->only(['title', 'scheduled_at', 'duration_minutes', 'notes', 'status']));

        if ($meeting->zoom_meeting_id && ZoomService::isConfigured()) {
            try {
                $zoomData = [];
                if ($request->has('title')) $zoomData['topic'] = $request->title;
                if ($request->has('scheduled_at')) $zoomData['start_time'] = self::zoomTime($request->scheduled_at);
                if ($request->has('duration_minutes')) $zoomData['duration'] = $request->duration_minutes;

                if (!empty($zoomData)) {
                    app(ZoomService::class)->updateMeeting($meeting->zoom_meeting_id, $zoomData);
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        AuditLog::create([
            'auditable_type' => Meeting::class,
            'auditable_id' => $meeting->id,
            'user_id' => $request->user()->id,
            'action' => 'meeting.updated',
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['meeting' => $meeting->fresh()]);
    }

    /** "2026-10-01T15:00:00Z" — UTC, whatever offset the client sent. */
    private static function zoomTime(string $scheduledAt): string
    {
        return \Carbon\Carbon::parse($scheduledAt)->utc()->format('Y-m-d\\TH:i:s\\Z');
    }

    public function destroy(Workspace $workspace, Meeting $meeting): JsonResponse
    {
        $this->authorize('delete', $meeting);

        if ($meeting->zoom_meeting_id && ZoomService::isConfigured()) {
            try {
                app(ZoomService::class)->deleteMeeting($meeting->zoom_meeting_id);
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $meeting->delete();

        return response()->json(['message' => 'تم حذف الاجتماع']);
    }

    public function complete(Meeting $meeting): JsonResponse
    {
        $this->authorize('update', $meeting);

        if ($meeting->status !== 'scheduled') {
            return response()->json(['message' => 'لا يمكن إكمال اجتماع غير مجدول'], 422);
        }

        $meeting->update([
            'status' => 'completed',
            'ended_at' => now(),
        ]);

        return response()->json(['meeting' => $meeting->fresh()]);
    }

    public function cancel(Meeting $meeting): JsonResponse
    {
        $this->authorize('update', $meeting);

        if ($meeting->status !== 'scheduled') {
            return response()->json(['message' => 'لا يمكن إلغاء اجتماع غير مجدول'], 422);
        }

        if ($meeting->zoom_meeting_id && ZoomService::isConfigured()) {
            try {
                app(ZoomService::class)->deleteMeeting($meeting->zoom_meeting_id);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $meeting->update(['status' => 'cancelled']);

        return response()->json(['meeting' => $meeting->fresh()]);
    }
}
