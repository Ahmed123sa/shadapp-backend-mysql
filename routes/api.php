<?php

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
use App\Domains\Notification\NotificationController;
use App\Domains\SubUser\SubUserController;
use App\Domains\Dashboard\DashboardController;
use App\Domains\Settings\SettingsController;
use App\Http\Controllers\ZoomWebhookController;
use Illuminate\Support\Facades\Route;
// Public auth routes
Route::post('/auth/register', [AuthController::class, 'registerSuperAdmin'])->middleware('throttle:5,1');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
Route::post('/auth/client/login', [AuthController::class, 'clientLogin'])->middleware('throttle:5,1');
Route::post('/auth/sub-user/login', [SubUserController::class, 'login'])->middleware('throttle:5,1');

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
Route::post('/auth/reset-password', [PasswordResetController::class, 'resetStaff'])->middleware('throttle:5,1');
Route::post('/auth/client/forgot-password', [PasswordResetController::class, 'forgotClient'])->middleware('throttle:10,60');
Route::post('/auth/client/reset-password', [PasswordResetController::class, 'resetClient'])->middleware('throttle:5,1');

// Zoom Webhook (no auth — verified by signature)
Route::post('/webhooks/zoom', [ZoomWebhookController::class, 'handle']);

// Dual-auth routes — allows both admin (sanctum) and client (client) guard
Route::middleware(['auth.any:sanctum,client,sub_user', 'scope.workspace'])->group(function () {
    Route::get('/workspaces/{workspace}/chat', [ChatController::class, 'index']);
    Route::post('/workspaces/{workspace}/chat', [ChatController::class, 'store']);
    Route::post('/workspaces/{workspace}/chat/mark-read', [ChatController::class, 'markAsRead']);
    Route::put('/chat/{chatMessage}', [ChatController::class, 'update']);
    Route::patch('/chat/{chatMessage}/require-action', [ChatController::class, 'toggleRequireAction']);
    Route::post('/chat/{chatMessage}/respond', [ChatController::class, 'respond']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);
    Route::post('/notifications/register-token', [NotificationController::class, 'registerToken']);

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
    Route::get('/workspaces/{workspace}/payments', [PaymentController::class, 'index']);
    Route::post('/workspaces/{workspace}/payments', [PaymentController::class, 'store']);
    Route::put('/workspaces/{workspace}/payments/{payment}', [PaymentController::class, 'update']);
    Route::get('/workspaces/{workspace}/payment-schedule', [PaymentController::class, 'getSchedule']);
    Route::get('/workspaces/{workspace}/approvals', [ApprovalController::class, 'index']);
    Route::post('/approvals/{approval}/respond', [ApprovalController::class, 'respond']);
    Route::get('/workspaces/{workspace}/meetings', [MeetingController::class, 'index']);

    // Files — client needs to upload/download too
    Route::get('/workspaces/{workspace}/files', [FileController::class, 'index']);
    Route::post('/workspaces/{workspace}/files', [FileController::class, 'upload']);
    Route::delete('/workspaces/{workspace}/files/{file}', [FileController::class, 'destroy']);
    Route::get('/contracts/{contract}/required-documents', [ContractController::class, 'requiredDocuments']);
    Route::get('/contracts/{contract}/files', [ContractController::class, 'files']);

    // Sub-users — accessible by admin, client, and sub_user
    Route::get('/clients/{client}/sub-users', [ClientController::class, 'subUsers']);
    Route::post('/clients/{client}/sub-users', [SubUserController::class, 'store']);
    Route::get('/sub-users/{subUser}', [SubUserController::class, 'show']);
    Route::match(['put', 'post'], '/sub-users/{subUser}/profile', [SubUserController::class, 'updateProfile']);
    Route::patch('/sub-users/{subUser}/permissions', [SubUserController::class, 'updatePermissions']);
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
    Route::get('/account-managers', [AccountManagerController::class, 'index']);
    Route::get('/account-managers/{manager}', [AccountManagerController::class, 'show']);
    Route::get('/account-managers/{manager}/stats', [AccountManagerController::class, 'stats']);
    Route::post('/account-managers', [AccountManagerController::class, 'store']);
    Route::put('/account-managers/{manager}', [AccountManagerController::class, 'update']);
    Route::delete('/account-managers/{manager}', [AccountManagerController::class, 'destroy']);

    // Clients
    Route::get('/clients', [ClientController::class, 'index']);
    Route::post('/clients', [ClientController::class, 'store']);
    Route::put('/clients/{client}', [ClientController::class, 'update']);
    Route::delete('/clients/{client}', [ClientController::class, 'destroy']);

    // Client profile + location + activity (SuperAdmin / AccountManager)
    Route::get('/clients/{client}/profile', [ClientController::class, 'profile']);
    Route::post('/clients/{client}/location', [ClientController::class, 'updateLocation']);
    Route::get('/clients/{client}/activity', [ClientController::class, 'activity']);

    // Workspace
    Route::post('/workspaces', [WorkspaceController::class, 'store']);
    Route::post('/workspaces/{workspace}/activate', [WorkspaceController::class, 'activate']);

    // All contracts/meetings/payments/files (cross-workspace)
    Route::get('/all-contracts', [ContractController::class, 'allContracts']);
    Route::get('/all-meetings', [MeetingController::class, 'allMeetings']);
    Route::get('/all-payments', [PaymentController::class, 'allPayments']);
    Route::get('/all-files', [FileController::class, 'allFiles']);

    // Contracts
    Route::post('/workspaces/{workspace}/contracts', [ContractController::class, 'store']);
    Route::get('/contracts/{contract}', [ContractController::class, 'show']);
    Route::put('/contracts/{contract}', [ContractController::class, 'update']);
    Route::delete('/contracts/{contract}', [ContractController::class, 'destroy']);
    Route::post('/contracts/{contract}/send', [ContractController::class, 'send']);
    Route::post('/contracts/{contract}/client-action', [ContractController::class, 'clientAction']);
    Route::post('/contracts/{contract}/company-approve', [ContractController::class, 'companyApprove']);
    Route::post('/contracts/{contract}/complete', [ContractController::class, 'complete']);
    Route::post('/contracts/{contract}/archive', [ContractController::class, 'archive']);
    Route::post('/contracts/{contract}/clauses', [ContractController::class, 'addClause']);
    Route::put('/contracts/{contract}/clauses/{clause}', [ContractController::class, 'updateClause']);
    Route::delete('/contracts/{contract}/clauses/{clause}', [ContractController::class, 'destroyClause']);

    // Payments
    Route::post('/payments/{payment}/review', [PaymentController::class, 'review']);
    Route::get('/payments/pending', [PaymentController::class, 'pending']);
    Route::post('/workspaces/{workspace}/payments/schedule', [PaymentController::class, 'schedule']);
    Route::post('/workspaces/{workspace}/payments/request', [PaymentController::class, 'requestPayment']);
    Route::put('/payments/{payment}/schedule', [PaymentController::class, 'updateSchedule']);
    Route::delete('/payments/{payment}/schedule', [PaymentController::class, 'deleteSchedule']);

    // Approvals
    Route::get('/approvals/pending', [ApprovalController::class, 'pending']);
    Route::post('/workspaces/{workspace}/approvals', [ApprovalController::class, 'store']);
    Route::get('/approvals/{approval}', [ApprovalController::class, 'show']);

    // Meetings
    Route::post('/workspaces/{workspace}/meetings', [MeetingController::class, 'store']);
    Route::put('/workspaces/{workspace}/meetings/{meeting}', [MeetingController::class, 'update']);
    Route::patch('/meetings/{meeting}/complete', [MeetingController::class, 'complete']);
    Route::patch('/meetings/{meeting}/cancel', [MeetingController::class, 'cancel']);
    Route::delete('/workspaces/{workspace}/meetings/{meeting}', [MeetingController::class, 'destroy']);

    // Files — review + definitions (admin only)
    Route::post('/files/{file}/review', [FileController::class, 'review']);
    Route::post('/workspaces/{workspace}/document-definitions', [FileController::class, 'storeDefinition']);
    Route::delete('/workspaces/{workspace}/document-definitions/{documentDefinition}', [FileController::class, 'destroyDefinition']);

    // Contract Clause Templates
    Route::get('/contract-clause-templates', [ContractController::class, 'templates']);
    Route::post('/contract-clause-templates', [ContractController::class, 'storeTemplate']);
    Route::put('/contract-clause-templates/{template}', [ContractController::class, 'updateTemplate']);
    Route::delete('/contract-clause-templates/{template}', [ContractController::class, 'destroyTemplate']);
    Route::post('/contract-clause-templates/reorder', [ContractController::class, 'reorderTemplates']);

    // Users list (for filters)
    Route::get('/users', function () {
        return \App\Models\User::select('id', 'name', 'email')->get();
    });

    // Audit & Reports
    Route::get('/audit-logs', [AuditController::class, 'index']);
    Route::get('/reports', [AuditController::class, 'reports']);

    // Notifications
    Route::post('/notifications/send-fcm', [NotificationController::class, 'sendFcm']);

    // System Settings. Reads are open to any authenticated user — the mobile
    // contract builder and the dashboard both need show_contract_dates, not
    // just SAs. Only update() is SA-gated (it checks isSuperAdmin itself and
    // whitelists the allowed keys).
    Route::get('/settings', [SettingsController::class, 'index']);
    Route::put('/settings', [SettingsController::class, 'update']);
    Route::get('/settings/tax-summary/{workspace}', [SettingsController::class, 'getTaxSummary']);
});
