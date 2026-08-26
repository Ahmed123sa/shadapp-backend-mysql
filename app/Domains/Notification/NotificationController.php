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
        $user = $request->user();

        if ($user instanceof SubUser) {
            $client = $user->client;
            $allNotifications = $client?->notifications()->latest()->get() ?? collect();
        } else {
            $allNotifications = $user?->notifications()->latest()->get() ?? collect();
        }

        if ($user instanceof User && $user->isAccountManager()) {
            $workspaceIds = $user->managedClients()
                ->with('workspace')
                ->get()
                ->pluck('workspace.id')
                ->filter()
                ->toArray();

            $allNotifications = $allNotifications->filter(function ($n) use ($workspaceIds) {
                $data = $n->data ?? [];
                if (isset($data['workspace_id'])) {
                    return in_array($data['workspace_id'], $workspaceIds);
                }
                if (isset($data['contract_id'])) {
                    $contract = Contract::find($data['contract_id']);
                    return $contract && in_array($contract->workspace_id, $workspaceIds);
                }
                if (isset($data['payment_id'])) {
                    $payment = Payment::find($data['payment_id']);
                    return $payment && in_array($payment->workspace_id, $workspaceIds);
                }
                if (isset($data['approval_id'])) {
                    $approval = Approval::find($data['approval_id']);
                    return $approval && in_array($approval->workspace_id, $workspaceIds);
                }
                return false;
            })->values();
        }

        $unreadCount = $allNotifications->whereNull('read_at')->count();

        $unreadClientIds = collect();
        if ($unreadCount > 0) {
            $unreadNotifications = $allNotifications->whereNull('read_at');
            foreach ($unreadNotifications as $n) {
                $data = $n->data ?? [];
                $workspaceId = $data['workspace_id'] ?? null;
                if (!$workspaceId && isset($data['contract_id'])) {
                    $contract = Contract::find($data['contract_id']);
                    $workspaceId = $contract?->workspace_id;
                } elseif (!$workspaceId && isset($data['payment_id'])) {
                    $payment = Payment::find($data['payment_id']);
                    $workspaceId = $payment?->workspace_id;
                } elseif (!$workspaceId && isset($data['approval_id'])) {
                    $approval = Approval::find($data['approval_id']);
                    $workspaceId = $approval?->workspace_id;
                }
                if ($workspaceId) {
                    $ws = Workspace::find($workspaceId);
                    if ($ws) $unreadClientIds->push($ws->client_id);
                }
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
        $user = $request->user();
        if ($user instanceof SubUser) {
            $user = $user->client;
        }
        $notification = $user?->notifications()->where('id', $id)->first();
        if ($notification) {
            $notification->markAsRead();
        }
        return response()->json(['message' => 'done']);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user instanceof SubUser) {
            $user = $user->client;
        }
        $user?->unreadNotifications()->update(['read_at' => now()]);
        return response()->json(['message' => 'done']);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if ($user instanceof SubUser) {
            $user = $user->client;
        }
        $notification = $user?->notifications()->where('id', $id)->first();
        if ($notification) {
            $notification->delete();
        }
        return response()->json(['message' => 'done']);
    }
}
