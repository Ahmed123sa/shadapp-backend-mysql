<?php

namespace App\Domains\Notification;

use App\Models\Approval;
use App\Models\Client;
use App\Models\Contract;
use App\Models\MobileNotificationToken;
use App\Models\Payment;
use App\Models\SubUser;
use App\Models\User;
use App\Models\Workspace;
use App\Services\FirebaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

class NotificationController extends Controller
{
    public function registerToken(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
            'device_type' => 'required|in:ios,android,web',
        ]);

        $user = $request->user() ?? $request->user('client') ?? $request->user('sub_user');

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        MobileNotificationToken::updateOrCreate(
            ['token' => $request->token],
            [
                'tokenable_id' => $user->id,
                'tokenable_type' => get_class($user),
                'device_type' => $request->device_type,
            ]
        );

        return response()->json(['message' => 'Token registered']);
    }

    /**
     * plans/notifications-badges-toasts-plan.md ن1 — logout never removed
     * this device's token, so whoever logged in next on the same phone kept
     * receiving the previous account's push notifications until the app was
     * fully closed and reopened. The mobile app calls this before
     * /auth/logout (which would otherwise revoke the token this endpoint
     * needs to authenticate).
     *
     * Deletes by token value alone, with no ownership check against the
     * authenticated user — matching registerToken() above, which likewise
     * reassigns a token's owner via updateOrCreate() without checking who
     * held it before. The token value itself is the only thing a caller
     * needs to act on it either way.
     */
    public function unregisterToken(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        MobileNotificationToken::where('token', $request->token)->delete();

        return response()->json(['message' => 'Token unregistered']);
    }

    public function sendFcm(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer',
            'user_type' => 'required|string',
            'title' => 'required|string|max:255',
            'body' => 'required|string',
        ]);

        $authUser = $request->user();
        if (!$authUser instanceof User) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (!$authUser->isSuperAdmin()) {
            if ($request->user_type === 'App\\Models\\Client') {
                $target = Client::find($request->user_id);
                if (!$target || $target->manager_id !== $authUser->id) {
                    return response()->json(['message' => 'Forbidden'], 403);
                }
            } else {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        try {
            $firebase = app(FirebaseService::class);
            $firebase->sendToUser(
                $request->user_id,
                $request->user_type,
                ['title' => $request->title, 'body' => $request->body]
            );

            return response()->json(['sent' => true]);
        } catch (\Exception $e) {
            Log::warning('sendFcm failed: ' . $e->getMessage());
            return response()->json(['sent' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function index(Request $request): JsonResponse
    {
        $authUser = $request->user();
        $subUser = $authUser instanceof SubUser ? $authUser : null;
        $user = $subUser ? $subUser->client : $authUser;

        $allNotifications = $user?->notifications()->latest()->get() ?? collect();

        if ($subUser) {
            $allNotifications = $allNotifications
                ->filter(fn ($n) => $subUser->canSeeNotificationType($n->data['type'] ?? null))
                ->values();
        }

        // ن13 — resolves every contract/payment/approval a notification
        // points at to its workspace_id in three batched whereIn() queries,
        // instead of a Contract::find()/Payment::find()/Approval::find() per
        // notification (and the AM filter below and the unread-clients-count
        // loop after it used to each run that N+1 separately — twice the
        // cost for nothing, since the answer for a given id never changes
        // within one request).
        $contractIds = [];
        $paymentIds = [];
        $approvalIds = [];
        foreach ($allNotifications as $n) {
            $data = $n->data ?? [];
            if (isset($data['contract_id'])) $contractIds[] = $data['contract_id'];
            if (isset($data['payment_id'])) $paymentIds[] = $data['payment_id'];
            if (isset($data['approval_id'])) $approvalIds[] = $data['approval_id'];
        }
        $contractWorkspaces = $contractIds ? Contract::whereIn('id', array_unique($contractIds))->pluck('workspace_id', 'id') : collect();
        $paymentWorkspaces = $paymentIds ? Payment::whereIn('id', array_unique($paymentIds))->pluck('workspace_id', 'id') : collect();
        $approvalWorkspaces = $approvalIds ? Approval::whereIn('id', array_unique($approvalIds))->pluck('workspace_id', 'id') : collect();

        $resolveWorkspaceId = function (array $data) use ($contractWorkspaces, $paymentWorkspaces, $approvalWorkspaces) {
            if (isset($data['workspace_id'])) return $data['workspace_id'];
            if (isset($data['contract_id'])) return $contractWorkspaces[$data['contract_id']] ?? null;
            if (isset($data['payment_id'])) return $paymentWorkspaces[$data['payment_id']] ?? null;
            if (isset($data['approval_id'])) return $approvalWorkspaces[$data['approval_id']] ?? null;
            return null;
        };

        if ($authUser instanceof User && $authUser->isAccountManager()) {
            $managedClients = $authUser->managedClients()->with('workspace')->get();
            $workspaceIds = $managedClients->pluck('workspace.id')->filter()->toArray();
            // ن3 — a manager's own birthday/meeting reminders had none of
            // workspace_id/contract_id/payment_id/approval_id at all (only
            // client_id, or nothing resolvable for meetings before today),
            // so they silently vanished from this list even though the push
            // notification for the same event reached the manager fine.
            // New reminders now carry workspace_id directly (see
            // BirthdayReminderNotification/MeetingReminderNotification), but
            // this client_id fallback also recovers already-stored ones —
            // BirthdayReminderNotification has always included client_id.
            $clientIds = $managedClients->pluck('id')->toArray();

            $allNotifications = $allNotifications->filter(function ($n) use ($workspaceIds, $clientIds, $resolveWorkspaceId) {
                $data = $n->data ?? [];
                $workspaceId = $resolveWorkspaceId($data);
                if ($workspaceId !== null) {
                    return in_array($workspaceId, $workspaceIds);
                }
                if (isset($data['client_id'])) {
                    return in_array($data['client_id'], $clientIds);
                }
                return false;
            })->values();
        }

        $unreadCount = $allNotifications->whereNull('read_at')->count();

        $unreadClientIds = collect();
        if ($unreadCount > 0) {
            $workspaceIdsNeeded = [];
            foreach ($allNotifications->whereNull('read_at') as $n) {
                $workspaceId = $resolveWorkspaceId($n->data ?? []);
                if ($workspaceId) $workspaceIdsNeeded[] = $workspaceId;
            }
            if ($workspaceIdsNeeded) {
                $unreadClientIds = Workspace::whereIn('id', array_unique($workspaceIdsNeeded))->pluck('client_id');
            }
        }
        $unreadClientsCount = $unreadClientIds->unique()->count();

        $notifications = $allNotifications->take(50);

        return response()->json([
            'notifications' => $notifications,
            'unread_count' => $unreadCount,
            'unread_clients_count' => $unreadClientsCount,
        ]);
    }

    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $authUser = $request->user();
        $subUser = $authUser instanceof SubUser ? $authUser : null;
        $user = $subUser ? $subUser->client : $authUser;
        $notification = $user?->notifications()->where('id', $id)->first();
        if ($notification && (!$subUser || $subUser->canSeeNotificationType($notification->data['type'] ?? null))) {
            $notification->markAsRead();
        }
        return response()->json(['message' => 'done']);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $authUser = $request->user();
        $subUser = $authUser instanceof SubUser ? $authUser : null;
        $user = $subUser ? $subUser->client : $authUser;

        if ($subUser) {
            // ن2 — only mark read the notifications this sub-user is actually
            // allowed to see; a blanket update(['read_at' => now()]) here
            // would silently clear the parent client's unread payments/
            // contracts/approvals notifications too, on behalf of a sub-user
            // who was never shown them in the first place.
            foreach ($user?->unreadNotifications ?? collect() as $notification) {
                if ($subUser->canSeeNotificationType($notification->data['type'] ?? null)) {
                    $notification->markAsRead();
                }
            }
        } else {
            $user?->unreadNotifications()->update(['read_at' => now()]);
        }

        return response()->json(['message' => 'done']);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $authUser = $request->user();
        $subUser = $authUser instanceof SubUser ? $authUser : null;
        $user = $subUser ? $subUser->client : $authUser;
        $notification = $user?->notifications()->where('id', $id)->first();
        if ($notification && (!$subUser || $subUser->canSeeNotificationType($notification->data['type'] ?? null))) {
            $notification->delete();
        }
        return response()->json(['message' => 'done']);
    }
}
