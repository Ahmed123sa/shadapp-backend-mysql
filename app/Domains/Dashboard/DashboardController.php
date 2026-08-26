<?php

namespace App\Domains\Dashboard;

use App\Models\Approval;
use App\Models\ChatMessage;
use App\Models\Client;
use App\Models\Contract;
use App\Models\FileEntry;
use App\Models\Payment;
use App\Models\SubUser;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class DashboardController extends Controller
{
    public function badgeCounts(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user instanceof Client) {
            return $this->clientCounts($user);
        }

        if ($user instanceof SubUser) {
            return $this->clientCounts($user->client);
        }

        $role = $user->role ?? '';

        if ($role === 'super_admin') {
            return $this->saCounts($user);
        }

        if ($role === 'account_manager') {
            return $this->amCounts($user);
        }

        return response()->json([
            'chat' => 0,
            'contracts' => 0,
            'approvals' => 0,
            'payments' => 0,
            'files' => 0,
            'notifications' => 0,
        ]);
    }

    private function clientCounts(Client $client): JsonResponse
    {
        $ws = $client->workspace;
        if (!$ws) {
            return response()->json([
                'chat' => 0,
                'contracts' => 0,
                'approvals' => 0,
                'payments' => 0,
                'files' => 0,
                'notifications' => 0,
            ]);
        }

        $wsId = $ws->id;

        $chat = ChatMessage::where('workspace_id', $wsId)
            ->where('sender_type', '!=', Client::class)
            ->whereNull('read_at')
            ->count();

        $contracts = Contract::where('workspace_id', $wsId)
            ->whereIn('status', ['sent', 'client_approved'])
            ->count();

        $approvals = Approval::where('workspace_id', $wsId)
            ->where('status', 'pending')
            ->count();

        $payments = Payment::where('workspace_id', $wsId)
            ->whereIn('status', ['scheduled', 'pending', 'overdue'])
            ->count();

        $files = FileEntry::where('workspace_id', $wsId)
            ->where('status', 'pending')
            ->count();

        return response()->json([
            'chat' => $chat,
            'contracts' => $contracts,
            'approvals' => $approvals,
            'payments' => $payments,
            'files' => $files,
            'notifications' => 0,
        ]);
    }

    private function saCounts($user): JsonResponse
    {
        $chat = ChatMessage::where('sender_type', '!=', get_class($user))
            ->whereNull('read_at')
            ->count();

        $pendingContracts = Contract::whereIn('status', ['sent', 'client_approved'])
            ->count();

        $pendingApprovals = Approval::where('status', 'pending')
            ->count();

        $payments = Payment::whereIn('status', ['scheduled', 'pending', 'overdue'])
            ->count();

        $files = FileEntry::where('status', 'pending')
            ->count();

        $notifications = $user->notifications()->whereNull('read_at')->count();

        return response()->json([
            'chat' => $chat,
            'contracts' => 0,
            'approvals' => $pendingApprovals + $pendingContracts,
            'payments' => $payments,
            'files' => $files,
            'notifications' => $notifications,
        ]);
    }

    private function amCounts($user): JsonResponse
    {
        $workspaceIds = Workspace::where('manager_id', $user->id)->pluck('id');

        $chat = ChatMessage::whereIn('workspace_id', $workspaceIds)
            ->where('sender_type', '!=', get_class($user))
            ->whereNull('read_at')
            ->count();

        $pendingContracts = Contract::whereIn('workspace_id', $workspaceIds)
            ->whereIn('status', ['sent', 'client_approved'])
            ->count();

        $pendingApprovals = Approval::whereIn('workspace_id', $workspaceIds)
            ->where('status', 'pending')
            ->count();

        $payments = Payment::whereIn('workspace_id', $workspaceIds)
            ->whereIn('status', ['scheduled', 'pending', 'overdue'])
            ->count();

        $files = FileEntry::whereIn('workspace_id', $workspaceIds)
            ->where('status', 'pending')
            ->count();

        $notifications = $user->notifications()->whereNull('read_at')->count();

        return response()->json([
            'chat' => $chat,
            'contracts' => 0,
            'approvals' => $pendingApprovals + $pendingContracts,
            'payments' => $payments,
            'files' => $files,
            'notifications' => $notifications,
        ]);
    }
}
