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
        if ($recipient) {
            try {
                $recipient->notify(new ChatMessageSentNotification($message));
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

            if ($approval->requester) {
                $approval->requester->notify(new \App\Notifications\ApprovalRespondedNotification($approval));
            }
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
        $senderType = get_class($user);

        $workspace->chatMessages()
            ->where('sender_type', '!=', $senderType)
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
