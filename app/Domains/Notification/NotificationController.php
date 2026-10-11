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

    /** How many notifications the list returns (unchanged from before). */
    private const LIST_LIMIT = 50;

    /** Read notifications are pulled in pages of this size until the list is full. */
    private const READ_CHUNK = 100;

    public function index(Request $request): JsonResponse
    {
        $authUser = $request->user();
        $subUser = $authUser instanceof SubUser ? $authUser : null;
        $user = $subUser ? $subUser->client : $authUser;

        if (!$user) {
            return response()->json(['notifications' => [], 'unread_count' => 0, 'unread_clients_count' => 0]);
        }

        // 11 Oct 2026 (performance) — this used to load EVERY notification
        // the user ever had into memory on each call (the web polls it every
        // 60 s) and filter them in PHP. The result is unchanged, but now:
        //  - unread ones are all loaded (they are what the counts are made
        //    of, and there are few of them), and
        //  - read ones are pulled newest first, a page at a time, only until
        //    there are enough visible ones to fill the list.
        // The newest LIST_LIMIT visible notifications are always within
        // "all visible unread" + "the newest LIST_LIMIT visible read", so the
        // list returned is exactly what the old code returned.
        $filter = $this->visibilityFilter($authUser, $subUser);

        $unread = $filter($user->notifications()->whereNull('read_at')->latest()->get());

        $read = collect();
        $offset = 0;
        while ($read->count() < self::LIST_LIMIT) {
            $page = $user->notifications()->whereNotNull('read_at')->latest()
                ->skip($offset)->take(self::READ_CHUNK)->get();
            if ($page->isEmpty()) {
                break;
            }
            $read = $read->concat($filter($page));
            $offset += self::READ_CHUNK;
            if ($page->count() < self::READ_CHUNK) {
                break;
            }
        }

        $notifications = $unread->concat($read)
            ->sortByDesc(fn ($n) => $n->created_at?->getTimestamp() ?? 0)
            ->take(self::LIST_LIMIT)
            ->values();

        $workspaceIdsNeeded = [];
        $resolve = $this->workspaceResolver($unread);
        foreach ($unread as $n) {
            $workspaceId = $resolve($n->data ?? []);
            if ($workspaceId) $workspaceIdsNeeded[] = $workspaceId;
        }
        $unreadClientsCount = $workspaceIdsNeeded
            ? Workspace::whereIn('id', array_unique($workspaceIdsNeeded))->pluck('client_id')->unique()->count()
            : 0;

        return response()->json([
            'notifications' => $notifications,
            'unread_count' => $unread->count(),
            'unread_clients_count' => $unreadClientsCount,
        ]);
    }

    /**
     * Who may see which stored notification — the same rules index() always
     * applied, now as a reusable filter so it can run on one batch at a time:
     * a sub-user only sees types its permissions allow; any non-super-admin
     * staff (manager or assistant) only sees notifications about their own
     * manager's clients; an assistant additionally never sees payment types.
     *
     * @return \Closure(\Illuminate\Support\Collection): \Illuminate\Support\Collection
     */
    private function visibilityFilter($authUser, ?SubUser $subUser): \Closure
    {
        $workspaceIds = null;
        $clientIds = null;
        if ($authUser instanceof User && !$authUser->isSuperAdmin()) {
            $managedClients = Client::where('manager_id', $authUser->ownerManagerId())->with('workspace')->get();
            $workspaceIds = $managedClients->pluck('workspace.id')->filter()->all();
            // ن3 — reminders stored before they carried workspace_id still
            // have client_id, so fall back to it.
            $clientIds = $managedClients->pluck('id')->all();
        }

        return function ($batch) use ($authUser, $subUser, $workspaceIds, $clientIds) {
            if ($subUser) {
                $batch = $batch->filter(fn ($n) => $subUser->canSeeNotificationType($n->data['type'] ?? null));
            }

            if ($workspaceIds !== null) {
                $resolve = $this->workspaceResolver($batch);
                $batch = $batch->filter(function ($n) use ($workspaceIds, $clientIds, $resolve) {
                    $data = $n->data ?? [];
                    $workspaceId = $resolve($data);
                    if ($workspaceId !== null) {
                        return in_array($workspaceId, $workspaceIds);
                    }
                    if (isset($data['client_id'])) {
                        return in_array($data['client_id'], $clientIds);
                    }
                    return false;
                });
            }

            // An assistant keeps no payment/finance notifications, and only
            // the areas their manager still allows.
            if ($authUser instanceof User && $authUser->isAssistant()) {
                $batch = $batch->filter(fn ($n) => $authUser->canSeeNotificationType($n->data['type'] ?? null));
            }

            return $batch->values();
        };
    }

    /**
     * ن13 — maps a notification's contract/payment/approval id to its
     * workspace in three batched whereIn() queries for the whole batch,
     * instead of one find() per notification.
     *
     * @return \Closure(array): mixed
     */
    private function workspaceResolver($batch): \Closure
    {
        $contractIds = [];
        $paymentIds = [];
        $approvalIds = [];
        foreach ($batch as $n) {
            $data = $n->data ?? [];
            if (isset($data['contract_id'])) $contractIds[] = $data['contract_id'];
            if (isset($data['payment_id'])) $paymentIds[] = $data['payment_id'];
            if (isset($data['approval_id'])) $approvalIds[] = $data['approval_id'];
        }
        $contractWorkspaces = $contractIds ? Contract::whereIn('id', array_unique($contractIds))->pluck('workspace_id', 'id') : collect();
        $paymentWorkspaces = $paymentIds ? Payment::whereIn('id', array_unique($paymentIds))->pluck('workspace_id', 'id') : collect();
        $approvalWorkspaces = $approvalIds ? Approval::whereIn('id', array_unique($approvalIds))->pluck('workspace_id', 'id') : collect();

        return function (array $data) use ($contractWorkspaces, $paymentWorkspaces, $approvalWorkspaces) {
            if (isset($data['workspace_id'])) return $data['workspace_id'];
            if (isset($data['contract_id'])) return $contractWorkspaces[$data['contract_id']] ?? null;
            if (isset($data['payment_id'])) return $paymentWorkspaces[$data['payment_id']] ?? null;
            if (isset($data['approval_id'])) return $approvalWorkspaces[$data['approval_id']] ?? null;
            return null;
        };
    }

    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $authUser = $request->user();
        $subUser = $authUser instanceof SubUser ? $authUser : null;
        $user = $subUser ? $subUser->client : $authUser;
        $notification = $user?->notifications()->where('id', $id)->first();
        $canSee = $subUser
            ? $subUser->canSeeNotificationType($notification?->data['type'] ?? null)
            : ($authUser instanceof User ? $authUser->canSeeNotificationType($notification?->data['type'] ?? null) : true);
        if ($notification && $canSee) {
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
        } elseif ($authUser instanceof User && $authUser->isAssistant()) {
            foreach ($user?->unreadNotifications ?? collect() as $notification) {
                if ($authUser->canSeeNotificationType($notification->data['type'] ?? null)) {
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
