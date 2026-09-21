<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to staff principals (App\Models\User) only.
 *
 * 20 Sept 2026 — this exists because the route group named `auth:sanctum`
 * in routes/api.php does NOT mean "staff only", even though its own comment
 * says "Dashboard - SuperAdmin / AccountManager" and it reads that way.
 * Sanctum's guard resolves *any* valid personal access token to whatever
 * model issued it, so a Client or SubUser token authenticates against
 * `auth:sanctum` exactly as happily as a staff one (documented in
 * DATA_SAFETY_PLAN.md §7.4, and proven by MeetingTest, which asserts a
 * client's real bearer token reaches the controller and is rejected there
 * with 403 rather than bouncing off the middleware with 401).
 *
 * That group is also genuinely mixed — /contracts/{contract}/client-action
 * lives in it and is meant for clients and sub-users — so the group itself
 * cannot simply be locked down. The guard has to be per-route, which is
 * what this is.
 *
 * Attaching it at the route is deliberate: the wrong assumption lives in
 * routes/api.php, so the correction belongs there too, visible in the same
 * place where the next person would otherwise repeat it. A controller that
 * merely calls $user->isSuperAdmin()/isAccountManager() to *scope* results
 * is not a substitute — those methods only exist on User, so a Client
 * reaching that line is a fatal "call to undefined method" (a 500) rather
 * than a refusal, and any code path that treats "not an account manager"
 * as "therefore a super admin" hands that caller everything.
 */
class StaffOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user() instanceof User, 403, 'غير مصرح لك بالوصول لهذه البيانات');

        return $next($request);
    }
}
