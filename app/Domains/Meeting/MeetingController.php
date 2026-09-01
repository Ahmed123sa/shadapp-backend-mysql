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
        $meetings = Meeting::with('workspace.client', 'contract', 'approval')
            ->when($user->isAccountManager(), fn($q) => $q->whereHas('workspace', fn($q) => $q->where('manager_id', $user->id)))
            ->latest()
            ->paginate(30);

        return response()->json(['meetings' => $meetings]);
    }

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('viewAny', Meeting::class);

        return response()->json(['meetings' => $workspace->meetings()->with('contract', 'approval')->latest()->paginate(30)]);
    }

    public function store(StoreMeetingRequest $request, Workspace $workspace): JsonResponse
    {
        if (!$request->user()->isSuperAdmin() && $workspace->manager_id !== $request->user()->id) {
            return response()->json(['message' => 'غير مصرح'], 403);
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
                $zoomMeeting = $zoom->createMeeting($request->title, $request->scheduled_at, $request->duration_minutes ?? 30);
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

    public function update(Request $request, Meeting $meeting): JsonResponse
    {
        $this->authorize('update', $meeting);

        $meeting->update($request->only(['title', 'scheduled_at', 'duration_minutes', 'notes', 'status']));

        if ($meeting->zoom_meeting_id && ZoomService::isConfigured()) {
            try {
                $zoomData = [];
                if ($request->has('title')) $zoomData['topic'] = $request->title;
                if ($request->has('scheduled_at')) $zoomData['start_time'] = $request->scheduled_at;
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

    public function destroy(Meeting $meeting): JsonResponse
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
