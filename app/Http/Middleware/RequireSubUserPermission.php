<?php

namespace App\Http\Middleware;

use App\Models\SubUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates an action-permission (one of the eleven flags on sub_users.permissions)
 * at the route level, so the list of guarded routes is readable straight out
 * of routes/api.php instead of buried inside each controller.
 *
 * Only SubUser is restricted here. The primary Client account and every staff
 * principal (User) are never permission-limited by this middleware — the
 * eleven flags exist only to scope what a client's own employees can do
 * inside their employer's workspace (see SUBUSER_PLAN.md §0 and §2).
 *
 * Before this middleware existed, only can_respond_approvals was actually
 * enforced (a manual check inside ApprovalController::respond). The other
 * action permissions — can_chat, can_approve_contracts,
 * can_upload_payment_proof, can_upload_files — only hid a tab in the
 * dashboard and mobile app; calling the API directly bypassed them entirely.
 * This middleware is what makes the remaining four real.
 *
 * The six *view* permissions (can_view_contracts, can_view_payments, etc.)
 * are deliberately NOT enforced here or anywhere server-side — that is a
 * considered decision (SUBUSER_PLAN.md §0), not an oversight, because the
 * data behind them belongs to the same company the sub-user works for and
 * tenant isolation (ScopeWorkspace + Workspace::canBeAccessedBy) already
 * bounds it to that company.
 */
class RequireSubUserPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if ($user instanceof SubUser && ! $user->hasPermission($permission)) {
            abort(403, 'ليس لديك صلاحية القيام بهذا الإجراء');
        }

        return $next($request);
    }
}
