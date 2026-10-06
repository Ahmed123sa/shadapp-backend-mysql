<?php

use App\Domains\Support\SupportController;
use App\Domains\Auth\AccountDeletionController;
use App\Domains\Auth\AuthController;
use App\Domains\Auth\PasswordResetController;
use App\Domains\AccountManager\AccountManagerController;
use App\Domains\Client\ClientController;
use App\Domains\Contract\ContractController;
use App\Domains\Payment\PaymentController;
use App\Domains\Workspace\WorkspaceController;
use App\Domains\Chat\ChatController;
use App\Domains\Approval\ApprovalController;
use App\Domains\Meeting\MeetingController;
use App\Domains\File\FileController;
use App\Models\FileEntry;
use App\Domains\Audit\AuditController;
use App\Domains\Audit\LoginAttemptController;
use App\Domains\Notification\NotificationController;
use App\Domains\SubUser\SubUserController;
use App\Domains\Team\TeamController;
use App\Domains\Dashboard\DashboardController;
use App\Domains\Settings\SettingsController;
use App\Http\Controllers\ZoomWebhookController;
use Illuminate\Support\Facades\Route;
// Public auth routes
Route::post('/auth/register', [AuthController::class, 'registerSuperAdmin'])->middleware('throttle:5,1');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
Route::post('/auth/client/login', [AuthController::class, 'clientLogin'])->middleware('throttle:5,1');
// A second sub-user login route (/auth/sub-user/login) used to live here.
// Nothing in the dashboard, mobile app, or tests ever called it, and unlike
// this route it never checked whether the parent client was archived — an
// open side door around client archiving. Removed; sub-users log in through
// clientLogin() above, which already resolves either model (see
// SUBUSER_PLAN.md §1.3).

// Password reset (staff + clients). SubUsers are managed by their parent
// client and are intentionally not included — see PasswordResetController.
//
// The forgot endpoints are limited to 10 per hour per IP, which is much
// tighter than login's 5/minute, for two reasons. They send email, so abuse
// costs money and can get the sending domain flagged for spam. And because
// they report whether an address is registered, the rate limit is the only
// thing stopping that from being used to enumerate the client list — at
// 10/hour, working through a list of a thousand addresses takes days.
// Loosening this materially weakens the endpoint; see the note in
// PasswordResetController.
Route::post('/auth/forgot-password', [PasswordResetController::class, 'forgotStaff'])->middleware('throttle:10,60');

// Public support form (App Store requires a Support URL with a contact form).
Route::post('/support', [SupportController::class, 'store'])->middleware('throttle:5,60');
Route::post('/auth/reset-password', [PasswordResetController::class, 'resetStaff'])->middleware('throttle:5,1');
Route::post('/auth/client/forgot-password', [PasswordResetController::class, 'forgotClient'])->middleware('throttle:10,60');
Route::post('/auth/client/reset-password', [PasswordResetController::class, 'resetClient'])->middleware('throttle:5,1');

// Zoom Webhook (no auth — verified by signature)
Route::post('/webhooks/zoom', [ZoomWebhookController::class, 'handle']);

// Dual-auth routes — allows both admin (sanctum) and client (client) guard
Route::middleware(['auth.any:sanctum,client,sub_user', 'scope.workspace'])->group(function () {
    Route::get('/workspaces/{workspace}/chat', [ChatController::class, 'index']);
    Route::post('/workspaces/{workspace}/chat', [ChatController::class, 'store'])->middleware(['subuser.can:can_chat', 'assistant.can:can_chat']);
    Route::post('/workspaces/{workspace}/chat/mark-read', [ChatController::class, 'markAsRead']);
    Route::put('/chat/{chatMessage}', [ChatController::class, 'update'])->middleware('assistant.can:can_chat');
    Route::patch('/chat/{chatMessage}/require-action', [ChatController::class, 'toggleRequireAction']);
    Route::post('/chat/{chatMessage}/respond', [ChatController::class, 'respond'])->middleware(['subuser.can:can_respond_approvals', 'assistant.can:can_chat']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);
    Route::post('/notifications/register-token', [NotificationController::class, 'registerToken']);
    Route::post('/notifications/unregister-token', [NotificationController::class, 'unregisterToken']);

    // In-app account deletion (App Store 5.1.1(v)) — any signed-in account
    // type; super admins are refused inside the controller. Switch it off
    // with ACCOUNT_DELETION_ENABLED=false (config/account_deletion.php).
    Route::delete('/auth/account', [AccountDeletionController::class, 'destroy'])->middleware('throttle:5,1');

    // Client show, signature + profile (client or manager)
    Route::get('/clients/{client}', [ClientController::class, 'show']);

    // Badge counts — accessible by all authenticated users
    Route::get('/badge-counts', [DashboardController::class, 'badgeCounts']);
    Route::post('/clients/{client}/sign', [ClientController::class, 'sign']);
    Route::delete('/clients/{client}/sign', [ClientController::class, 'deleteSign']);
    Route::match(['put', 'post'], '/clients/{client}/profile', [ClientController::class, 'profileUpdate']);

    // Workspace — client needs to load their own workspace
    Route::get('/workspaces/{workspace}', [WorkspaceController::class, 'show']);

    // Client-features — accessible by both client and manager
    Route::get('/workspaces/{workspace}/contracts', [ContractController::class, 'index']);
    Route::get('/workspaces/{workspace}/payments', [PaymentController::class, 'index'])->middleware('not.assistant');
    Route::post('/workspaces/{workspace}/payments', [PaymentController::class, 'store'])->middleware(['subuser.can:can_upload_payment_proof', 'not.assistant']);
    Route::put('/workspaces/{workspace}/payments/{payment}', [PaymentController::class, 'update'])->middleware(['subuser.can:can_upload_payment_proof', 'not.assistant']);
    Route::get('/workspaces/{workspace}/payment-schedule', [PaymentController::class, 'getSchedule'])->middleware('not.assistant');
    Route::get('/workspaces/{workspace}/approvals', [ApprovalController::class, 'index']);
    // No subuser.can guard here: ApprovalPolicy::respond() already restricts
    // this action to staff (super admin / the workspace's manager) — a
    // Client or SubUser can never reach this method regardless of any
    // permission flag. The actual client/sub-user approval flow is
    // /chat/{chatMessage}/respond above, which is where can_respond_approvals
    // is enforced. See SUBUSER_PLAN.md §2.
    Route::post('/approvals/{approval}/respond', [ApprovalController::class, 'respond'])->middleware('not.assistant');
    Route::get('/workspaces/{workspace}/meetings', [MeetingController::class, 'index']);

    // Files — client needs to upload/download too
    Route::get('/workspaces/{workspace}/files', [FileController::class, 'index'])->middleware('assistant.can:can_view_files');
    Route::post('/workspaces/{workspace}/files', [FileController::class, 'upload'])->middleware('subuser.can:can_upload_files');
    Route::delete('/workspaces/{workspace}/files/{file}', [FileController::class, 'destroy']);
    Route::get('/contracts/{contract}/required-documents', [ContractController::class, 'requiredDocuments']);
    Route::get('/contracts/{contract}/files', [ContractController::class, 'files'])->middleware('assistant.can:can_view_files');

    // Sub-users — accessible by admin, client, and sub_user
    Route::get('/sub-user-permissions', [SubUserController::class, 'permissionKeys']);
    Route::get('/clients/{client}/sub-users', [ClientController::class, 'subUsers']);
    Route::post('/clients/{client}/sub-users', [SubUserController::class, 'store']);
    Route::get('/sub-users/{subUser}', [SubUserController::class, 'show']);
    Route::match(['put', 'post'], '/sub-users/{subUser}/profile', [SubUserController::class, 'updateProfile']);
    Route::patch('/sub-users/{subUser}/permissions', [SubUserController::class, 'updatePermissions']);
    Route::patch('/sub-users/{subUser}/password', [SubUserController::class, 'changePassword']);
    Route::delete('/sub-users/{subUser}', [SubUserController::class, 'destroy']);
});

// Authenticated routes (Dashboard - SuperAdmin / AccountManager)
Route::middleware(['auth:sanctum', 'scope.workspace'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/sign', [AuthController::class, 'sign']);
    Route::delete('/auth/sign', [AuthController::class, 'deleteSign']);
    Route::match(['put', 'post'], '/auth/me', [AuthController::class, 'updateProfile']);

    // Account Manager management (SuperAdmin only)
    Route::get('/account-managers', [AccountManagerController::class, 'index'])->middleware('not.assistant');
    Route::get('/account-managers/{manager}', [AccountManagerController::class, 'show'])->middleware('not.assistant');
    Route::get('/account-managers/{manager}/stats', [AccountManagerController::class, 'stats'])->middleware('not.assistant');
    Route::post('/account-managers', [AccountManagerController::class, 'store'])->middleware('not.assistant');
    Route::put('/account-managers/{manager}', [AccountManagerController::class, 'update'])->middleware('not.assistant');
    // No delete route: an account manager is never deleted — see
    // AccountManagerController for the reasoning (deleting a manager used
    // to cascade-delete every one of their clients, contracts, payments and
    // signatures). Deactivate/activate below is the replacement.
    Route::post('/account-managers/{manager}/deactivate', [AccountManagerController::class, 'deactivate'])->middleware('not.assistant');
    Route::post('/account-managers/{manager}/activate', [AccountManagerController::class, 'activate'])->middleware('not.assistant');

    // My assistants (account manager only — TeamController refuses everyone
    // else, super admin included; see MANAGER_ASSISTANT_PLAN.md ق١/ق٩).
    Route::get('/assistant-permissions', [TeamController::class, 'permissionKeys']);
    Route::get('/team', [TeamController::class, 'index']);
    Route::post('/team', [TeamController::class, 'store']);
    Route::put('/team/{assistant}', [TeamController::class, 'update']);
    Route::post('/team/{assistant}/deactivate', [TeamController::class, 'deactivate']);
    Route::post('/team/{assistant}/activate', [TeamController::class, 'activate']);
    Route::patch('/team/{assistant}/password', [TeamController::class, 'changePassword']);
    Route::delete('/team/{assistant}', [TeamController::class, 'destroy']);
    Route::get('/team/{assistant}/activity', [TeamController::class, 'activity']);

    // Clients
    Route::get('/clients', [ClientController::class, 'index']);
    Route::post('/clients', [ClientController::class, 'store'])->middleware('not.assistant');
    Route::put('/clients/{client}', [ClientController::class, 'update'])->middleware('assistant.can:can_edit_clients');
    // No delete route: see ClientController — same reasoning as managers
    // above, minus the cascade risk but with the same "gone forever" one.
    Route::post('/clients/{client}/transfer', [ClientController::class, 'transfer'])->middleware('not.assistant');
    Route::post('/clients/{client}/archive', [ClientController::class, 'archive'])->middleware('not.assistant');
    Route::post('/clients/{client}/unarchive', [ClientController::class, 'unarchive'])->middleware('not.assistant');

    // Client profile + location + activity (SuperAdmin / AccountManager)
    Route::get('/clients/{client}/profile', [ClientController::class, 'profile']);
    Route::post('/clients/{client}/location', [ClientController::class, 'updateLocation'])->middleware('assistant.can:can_edit_clients');
    Route::get('/clients/{client}/activity', [ClientController::class, 'activity']);

    // Workspace
    Route::post('/workspaces', [WorkspaceController::class, 'store'])->middleware('not.assistant');
    Route::post('/workspaces/{workspace}/activate', [WorkspaceController::class, 'activate'])->middleware('not.assistant');

    // All contracts/meetings/payments/files (cross-workspace)
    Route::get('/all-contracts', [ContractController::class, 'allContracts']);
    Route::get('/all-meetings', [MeetingController::class, 'allMeetings']);
    Route::get('/all-payments', [PaymentController::class, 'allPayments'])->middleware('not.assistant');
    Route::get('/all-files', [FileController::class, 'allFiles'])->middleware('assistant.can:can_view_files');

    // Contracts
    Route::post('/workspaces/{workspace}/contracts', [ContractController::class, 'store'])->middleware('assistant.can:can_manage_contracts');
    Route::get('/contracts/{contract}', [ContractController::class, 'show']);
    Route::put('/contracts/{contract}', [ContractController::class, 'update'])->middleware('assistant.can:can_manage_contracts');
    Route::delete('/contracts/{contract}', [ContractController::class, 'destroy'])->middleware('assistant.can:can_manage_contracts');
    Route::post('/contracts/{contract}/send', [ContractController::class, 'send'])->middleware('assistant.can:can_manage_contracts');
    Route::post('/contracts/{contract}/client-action', [ContractController::class, 'clientAction'])->middleware('subuser.can:can_approve_contracts');
    Route::post('/contracts/{contract}/company-approve', [ContractController::class, 'companyApprove'])->middleware('not.assistant');
    Route::post('/contracts/{contract}/complete', [ContractController::class, 'complete'])->middleware('not.assistant');
    Route::post('/contracts/{contract}/archive', [ContractController::class, 'archive'])->middleware('not.assistant');
    Route::post('/contracts/{contract}/clauses', [ContractController::class, 'addClause'])->middleware('assistant.can:can_manage_contracts');
    Route::put('/contracts/{contract}/clauses/{clause}', [ContractController::class, 'updateClause'])->middleware('assistant.can:can_manage_contracts');
    Route::delete('/contracts/{contract}/clauses/{clause}', [ContractController::class, 'destroyClause'])->middleware('assistant.can:can_manage_contracts');

    // Payments
    Route::post('/payments/{payment}/review', [PaymentController::class, 'review'])->middleware('not.assistant');
    Route::get('/payments/pending', [PaymentController::class, 'pending'])->middleware('not.assistant');
    Route::post('/workspaces/{workspace}/payments/schedule', [PaymentController::class, 'schedule'])->middleware('not.assistant');
    Route::post('/workspaces/{workspace}/payments/request', [PaymentController::class, 'requestPayment'])->middleware('not.assistant');
    Route::put('/payments/{payment}/schedule', [PaymentController::class, 'updateSchedule'])->middleware('not.assistant');
    Route::delete('/payments/{payment}/schedule', [PaymentController::class, 'deleteSchedule'])->middleware('not.assistant');

    // Approvals
    Route::get('/approvals/pending', [ApprovalController::class, 'pending']);
    Route::post('/workspaces/{workspace}/approvals', [ApprovalController::class, 'store'])->middleware('assistant.can:can_manage_approvals');
    Route::get('/approvals/{approval}', [ApprovalController::class, 'show']);

    // Meetings
    Route::post('/workspaces/{workspace}/meetings', [MeetingController::class, 'store'])->middleware('assistant.can:can_manage_meetings');
    Route::put('/workspaces/{workspace}/meetings/{meeting}', [MeetingController::class, 'update'])->middleware('assistant.can:can_manage_meetings');
    Route::post('/meetings/{meeting}/enter', [MeetingController::class, 'enter'])->middleware('assistant.can:can_manage_meetings');
    Route::patch('/meetings/{meeting}/complete', [MeetingController::class, 'complete'])->middleware('assistant.can:can_manage_meetings');
    Route::patch('/meetings/{meeting}/cancel', [MeetingController::class, 'cancel'])->middleware('assistant.can:can_manage_meetings');
    Route::delete('/workspaces/{workspace}/meetings/{meeting}', [MeetingController::class, 'destroy'])->middleware('assistant.can:can_manage_meetings');

    // Files — review + definitions (admin only)
    Route::post('/files/{file}/review', [FileController::class, 'review'])->middleware('assistant.can:can_review_files');
    Route::post('/workspaces/{workspace}/document-definitions', [FileController::class, 'storeDefinition'])->middleware('not.assistant');
    Route::delete('/workspaces/{workspace}/document-definitions/{documentDefinition}', [FileController::class, 'destroyDefinition'])->middleware('not.assistant');

    // Contract Clause Templates
    Route::get('/contract-clause-templates', [ContractController::class, 'templates'])->middleware('assistant.can:can_manage_contracts');
    Route::post('/contract-clause-templates', [ContractController::class, 'storeTemplate'])->middleware('not.assistant');
    Route::put('/contract-clause-templates/{template}', [ContractController::class, 'updateTemplate'])->middleware('not.assistant');
    Route::delete('/contract-clause-templates/{template}', [ContractController::class, 'destroyTemplate'])->middleware('not.assistant');
    Route::post('/contract-clause-templates/reorder', [ContractController::class, 'reorderTemplates'])->middleware('not.assistant');

    // Users list (for filters). staff.only because this group is NOT
    // staff-only despite its name — see the middleware's docblock. Without
    // it this route handed the full staff directory (id, name, email) to
    // any authenticated client or sub-user token: it never touches
    // $request->user(), so unlike the two below it didn't even fail loudly,
    // it just answered.
    // Super admins and account managers keep the full directory (the
    // dashboard's filters need names); any other staff role gets just
    // itself, so a new role never inherits the whole staff list.
    Route::get('/users', function (\Illuminate\Http\Request $request) {
        $user = $request->user();
        return \App\Models\User::select('id', 'name', 'email')
            ->when(!$user->isSuperAdmin() && !$user->isAccountManager(), fn ($q) => $q->where('id', $user->id))
            // Assistants are the manager's business: the super admin never
            // sees them here, and a manager sees only their own.
            ->when($user->isSuperAdmin(), fn ($q) => $q->where('role', '!=', \App\Models\User::ROLE_MANAGER_ASSISTANT))
            ->when($user->isAccountManager(), fn ($q) => $q->where(function ($w) use ($user) {
                $w->where('role', '!=', \App\Models\User::ROLE_MANAGER_ASSISTANT)
                  ->orWhere('parent_manager_id', $user->id);
            }))
            ->get();
    })->middleware('staff.only');

    // Audit & Reports. Both call $user->isAccountManager() to scope their
    // results, which is a method only App\Models\User has — a client or
    // sub-user token reaching them was a fatal error (500), and the
    // "not an account manager" branch treats the caller as a super admin,
    // i.e. hands over the entire unscoped audit log. staff.only is what
    // actually keeps non-staff out; the scoping below it is not a gate.
    Route::get('/audit-logs', [AuditController::class, 'index'])->middleware(['staff.only', 'not.assistant']);
    Route::get('/reports', [AuditController::class, 'reports'])->middleware(['staff.only', 'not.assistant']);

    // Server-computed dashboard cards (server-side-stats-plan.md). Same
    // gate as /reports, for the same reason: it calls isAccountManager()
    // directly, which a client/sub-user token doesn't have.
    Route::get('/dashboard/stats', [DashboardController::class, 'stats'])->middleware('staff.only');

    // The list behind that same approvals card/badge
    // (pending-approvals-plan.md ك1) — same gate, same reason.
    Route::get('/dashboard/pending-approvals', [DashboardController::class, 'pendingApprovals'])->middleware('staff.only');

    // Failed sign-ins. Separate from /audit-logs because they are a
    // separate table for a reason — see the create_login_attempts_table
    // migration. staff.only for the same reason as the two above; the
    // per-manager scoping inside the controller narrows staff against each
    // other and is not the gate.
    Route::get('/login-attempts', [LoginAttemptController::class, 'index'])->middleware(['staff.only', 'not.assistant']);

    // Notifications
    Route::post('/notifications/send-fcm', [NotificationController::class, 'sendFcm'])->middleware('not.assistant');

    // System Settings. Reads are open to any authenticated user — the mobile
    // contract builder and the dashboard both need show_contract_dates, not
    // just SAs. Only update() is SA-gated (it checks isSuperAdmin itself and
    // whitelists the allowed keys).
    Route::get('/settings', [SettingsController::class, 'index']);
    Route::put('/settings', [SettingsController::class, 'update'])->middleware('not.assistant');
    Route::get('/settings/tax-summary/{workspace}', [SettingsController::class, 'getTaxSummary'])->middleware('not.assistant');
});
