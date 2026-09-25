<?php

namespace App\Domains\Chat;

use App\Models\ChatMessage;
use App\Models\Approval;
use App\Models\Workspace;
use App\Models\AuditLog;
use App\Domains\Chat\MessageSent;
use App\Notifications\ChatMessageSentNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Services\ApprovalPdfService;
use App\Support\UploadRules;

class ChatController extends Controller
{
    public function index(Workspace $workspace): JsonResponse
    {
        $messages = $workspace->chatMessages()
            ->with('sender', 'approval.certificate', 'replyTo.sender')
            ->latest()
            ->take(100)
            ->get()
            ->reverse()
            ->values();

        return response()->json(['messages' => $messages]);
    }

    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        if ($workspace->isClientArchived()) {
            return response()->json(['message' => 'العميل ده متأرشف، مينفعش تتبعت له رسايل جديدة. فُك الأرشفة الأول.'], 422);
        }

        $request->validate([
            'message' => 'nullable|string',
            'type' => 'in:text,file,meeting',
            'file' => UploadRules::document(),
            'metadata' => 'nullable|array',
            'requires_action' => 'boolean',
            'reply_to_id' => 'nullable|integer|exists:chat_messages,id',
        ]);

        $sender = $request->user();
        if (!$sender) {
            Log::warning('Chat: no authenticated sender', [
                'sanctum_guard' => \Illuminate\Support\Facades\Auth::guard('sanctum')->check(),
                'client_guard' => \Illuminate\Support\Facades\Auth::guard('client')->check(),
                'ws_id' => $workspace->id,
            ]);
            return response()->json(['message' => 'غير مصرح'], 401);
        }
        $senderType = get_class($sender);

        $fileUrl = null;
        $fileName = null;
        $fileType = null;
        $fileSize = null;

        if ($request->hasFile('file')) {
            $path = $request->file('file')->store('chat-attachments', 'public');
            $fileUrl = Storage::url($path);
            $fileName = $request->file('file')->getClientOriginalName();
            $fileType = $request->file('file')->getMimeType();
            $fileSize = $request->file('file')->getSize();
        }

        $message = $workspace->chatMessages()->create([
            'sender_type' => $senderType,
            'sender_id' => $sender->id,
            'message' => $request->message,
            'type' => $request->hasFile('file') ? 'file' : ($request->type ?? 'text'),
            'file_url' => $fileUrl,
            'metadata' => $request->metadata,
            'requires_action' => $request->requires_action ?? false,
            'reply_to_id' => $request->reply_to_id,
        ]);

        if ($request->boolean('requires_action')) {
            $approval = $workspace->approvals()->create([
                'title' => 'موافقة مطلوبة: ' . Str::limit($request->message ?? 'رسالة', 50),
                'description' => $request->message,
                'approvable_type' => 'chat_message',
                'approvable_id' => $message->id,
                'reference_no' => 'APP-' . strtoupper(Str::random(10)),
                'requested_by' => $sender->id,
                'status' => 'pending',
            ]);
            $message->update(['approval_id' => $approval->id]);
        }

        // If a file was uploaded, also save as FileEntry
        if ($request->hasFile('file')) {
            $workspace->files()->create([
                'uploaded_by_type' => $senderType,
                'uploaded_by_id' => $sender->id,
                'file_url' => $fileUrl,
                'name' => $fileName,
                'type' => $fileType,
                'size' => $fileSize,
                'status' => $senderType === \App\Models\Client::class ? 'pending' : 'approved',
            ]);
        }

        try {
            broadcast(new MessageSent($message))->toOthers();
        } catch (\Exception $e) {
            Log::warning('Chat broadcast failed (non-critical): ' . $e->getMessage());
        }

        // إرسال إشعار FCM للطرف الآخر
        $recipient = null;
        if ($senderType === \App\Models\User::class) {
            $recipient = $workspace->client;
        } elseif ($senderType === \App\Models\Client::class) {
            $recipient = $workspace->manager;
        } elseif ($senderType === \App\Models\SubUser::class) {
            $recipient = $workspace->manager;
        }
        // workspace->manager is current ownership, not history, so it
        // shouldn't structurally be able to point at a deactivated manager
        // (deactivation requires zero managed clients first) — but the
        // check is cheap and keeps this consistent with the other
        // notification call sites.
        if ($recipient instanceof \App\Models\User && !$recipient->isActive()) {
            $recipient = null;
        }
        if ($recipient) {
            // plans/notifications-badges-toasts-plan.md ن16 (§3 س1) — don't
            // push again if the recipient already has an unread chat
            // notification for this workspace from the last 5 minutes (the
            // database row and the realtime broadcast still happen below via
            // notify(); only the FCM channel gets skipped inside the
            // notification's own via()).
            $skipPush = $recipient->notifications()
                ->where('data->type', 'chat')
                ->where('data->workspace_id', $workspace->id)
                ->whereNull('read_at')
                ->where('created_at', '>=', now()->subMinutes(5))
                ->exists();
            try {
                $recipient->notify(new ChatMessageSentNotification($message, $skipPush));
            } catch (\Exception $e) {
                Log::warning('Chat notification failed: ' . $e->getMessage());
            }
        }

        return response()->json(['message' => $message->load('sender', 'replyTo.sender')], 201);
    }

    public function toggleRequireAction(Request $request, ChatMessage $chatMessage): JsonResponse
    {
        abort_unless($chatMessage->workspace->canBeAccessedBy($request->user()), 403, 'غير مصرح لك بالوصول إلى مساحة العمل هذه');

        $newValue = !$chatMessage->requires_action;

        $chatMessage->update(['requires_action' => $newValue]);

        // When enabling requires_action, create an Approval record automatically
        if ($newValue && !$chatMessage->approval_id) {
            $workspace = $chatMessage->workspace;
            $approval = $workspace->approvals()->create([
                'title' => 'موافقة مطلوبة: ' . Str::limit($chatMessage->message ?? 'رسالة', 50),
                'description' => $chatMessage->message,
                'approvable_type' => 'chat_message',
                'approvable_id' => $chatMessage->id,
                'reference_no' => 'APP-' . strtoupper(Str::random(10)),
                'requested_by' => $request->user()?->id ?? $chatMessage->sender_id,
                'status' => 'pending',
            ]);

            $chatMessage->update(['approval_id' => $approval->id]);
        }

        return response()->json(['message' => $chatMessage->fresh()->load('sender', 'approval')]);
    }

    public function respond(Request $request, ChatMessage $chatMessage): JsonResponse
    {
        $request->validate([
            'action' => 'required|in:approved,edit_requested',
            'reason' => 'nullable|string|max:1000',
        ]);

        $user = $request->user();
        abort_unless($chatMessage->workspace->canBeAccessedBy($user), 403, 'غير مصرح لك بالوصول إلى مساحة العمل هذه');
        $signature = $user instanceof \App\Models\Client ? $user->signature_data : null;

        $chatMessage->update([
            'action_taken' => true,
            'action_result' => $request->action,
            'responded_at' => now(),
        ]);

        // If there's a linked approval, update it too
        $approval = $chatMessage->approval;
        if ($approval) {
            $approval->update([
                'status' => $request->action === 'approved' ? 'approved' : 'edit_requested',
                'client_action' => $request->action,
                'signature' => $signature,
                'responded_at' => now(),
                'reason' => $request->input('reason'),
            ]);

            if ($request->action === 'approved') {
                $pdfPath = app(ApprovalPdfService::class)->generateCertificate($approval);
                $approval->certificate()->create([
                    'pdf_url' => $pdfPath,
                    'generated_at' => now(),
                ]);
            }

            // 23 Sept 2026 — this used to notify the requester directly and
            // nothing else, so the approval certificate email never went out
            // for a client's answer (this is the route clients actually use;
            // ApprovalController::respond() is staff-only). The shared event
            // sends the email to the requester and the client, skips a
            // deactivated requester, and never lets a failed send break the
            // client's response.
            \App\Events\ApprovalResponded::dispatch($approval);
        }

        // 23 Sept 2026 — push the updated card (approved / edit requested)
        // to anyone with this chat open; without it the manager only saw the
        // client's answer after a refresh. Sent after the certificate exists
        // and with it loaded, because mobile replaces the whole message and
        // the card's "download certificate" button reads
        // approval.certificate.pdf_url.
        try {
            $chatMessage->load('approval.certificate');
            broadcast(new \App\Events\MessageUpdated($chatMessage))->toOthers();
        } catch (\Throwable $e) {
            Log::warning('Chat respond broadcast failed (non-critical): ' . $e->getMessage());
        }

        AuditLog::create(array_filter([
            'auditable_type' => ChatMessage::class,
            'auditable_id' => $chatMessage->id,
            'client_id' => $user instanceof \App\Models\Client ? $user->id : null,
            'action' => 'chat.responded.' . $request->action,
            'ip_address' => $request->ip(),
        ]));

        return response()->json(['message' => $chatMessage->fresh()->load('sender', 'approval.certificate')]);
    }

    public function markAsRead(Workspace $workspace, Request $request): JsonResponse
    {
        $user = $request->user();

        // plans/notifications-badges-toasts-plan.md ن7 (§3 س5) — this used to
        // compare `sender_type != get_class($user)`. When a sub-user (class
        // SubUser) opened the chat, that marked the PRIMARY CLIENT's own
        // messages as read too (Client::class != SubUser::class), even
        // though the sub-user reading something has nothing to do with
        // whether the client's own outgoing messages are "read". There's no
        // per-user read table (the real fix — see the plan), so the
        // workaround treats Client and SubUser as one "client side": a
        // client-side reader marks staff (User) messages read, and a staff
        // reader marks client-side (Client or SubUser) messages read —
        // whichever one of them actually opened the chat.
        $isClientSide = $user instanceof \App\Models\Client || $user instanceof \App\Models\SubUser;

        $query = $workspace->chatMessages()->whereNull('read_at');
        if ($isClientSide) {
            $query->where('sender_type', \App\Models\User::class);
        } else {
            $query->whereIn('sender_type', [\App\Models\Client::class, \App\Models\SubUser::class]);
        }
        $query->update(['read_at' => now()]);

        // ن16 (§3 س1) — mark this workspace's chat notifications read too,
        // so the bell's unread count actually reflects that the chat was
        // just read instead of waiting for the next poll to still count 30
        // one-per-message notifications. Chat notifications are always
        // created against the recipient side's "owner" record (the client
        // for a staff-sent message, the assigned manager for a client/
        // sub-user-sent one — see ChatController::store()), never against a
        // sub-user directly, so a sub-user's own read here clears the
        // parent client's notifications.
        $notifiable = $isClientSide ? $workspace->client : $user;
        $notifiable?->notifications()
            ->where('data->type', 'chat')
            ->where('data->workspace_id', $workspace->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'done']);
    }

    public function update(Request $request, ChatMessage $chatMessage): JsonResponse
    {
        $user = $request->user();

        if ($chatMessage->sender_type !== get_class($user) || $chatMessage->sender_id !== $user->id) {
            abort(403, 'غير مصرح لك بتعديل هذه الرسالة');
        }

        if ($chatMessage->approval_id || $chatMessage->type !== 'text') {
            abort(422, 'لا يمكن تعديل هذه الرسالة');
        }

        $request->validate(['message' => 'required|string|max:5000']);

        $chatMessage->update([
            'message' => $request->message,
            'edited_at' => now(),
        ]);

        try {
            broadcast(new \App\Events\MessageUpdated($chatMessage))->toOthers();
        } catch (\Exception $e) {
            Log::warning('Chat edit broadcast failed (non-critical): ' . $e->getMessage());
        }

        AuditLog::create(array_filter([
            'auditable_type' => ChatMessage::class,
            'auditable_id' => $chatMessage->id,
            'client_id' => $user instanceof \App\Models\Client ? $user->id : null,
            'action' => 'chat.message_edited',
            'ip_address' => $request->ip(),
        ]));

        return response()->json(['message' => $chatMessage->fresh()->load('sender')]);
    }
}
