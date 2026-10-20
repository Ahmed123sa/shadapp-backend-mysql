<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two role-level walls, applied to every authenticated route group:
 *
 * 1. Company staff members are DENIED BY DEFAULT (STAFF_TEAMS_PLAN.md §٣.٣):
 *    a route rejects them unless its path is in ALLOWED_PATHS. A route added
 *    later is therefore closed to staff members automatically; nobody has to
 *    remember to block it. Later phases extend the list (clients they are
 *    assigned to, chat, approvals, meetings).
 * 2. Manager assistants are refused while the feature is switched off
 *    (config/team.php) — this also cuts sessions that were open when it was.
 */
class RestrictRoles
{
    /** Paths (Request::is patterns) a staff member may call. */
    public const ALLOWED_PATHS = [
        'api/auth/logout',
        'api/auth/me',
        // Realtime auth. The channel callbacks (routes/channels.php) still
        // refuse every workspace channel for a staff member and allow only
        // their own user channel, which is what live notifications need.
        'api/broadcasting/auth',
        'api/notifications',
        'api/notifications/*',
    ];

    /**
     * Routes a staff member may call, as "METHOD route-template" — matched on
     * the exact registered template, so `api/clients/{client}` does not also
     * open `api/clients/{client}/sub-users`. What they may touch inside these
     * is limited to the clients they are assigned to (Workspace /
     * ClientPolicy / MeetingPolicy). Chat, approval requests and meetings
     * only — nothing about contracts, payments, files or settings.
     */
    public const ALLOWED_ROUTES = [
        'GET api/clients',
        'GET api/clients/{client}',
        'GET api/workspaces/{workspace}',
        'GET api/workspaces/{workspace}/chat',
        'POST api/workspaces/{workspace}/chat',
        'POST api/workspaces/{workspace}/chat/mark-read',
        'PUT api/chat/{chatMessage}',
        'GET api/workspaces/{workspace}/approvals',
        'POST api/workspaces/{workspace}/approvals',
        'GET api/workspaces/{workspace}/meetings',
        'POST api/meetings/{meeting}/enter',
    ];

    /**
     * Per-client permission each allowed route needs (PLAN_2 §٣.٣). Checked
     * on top of ALLOWED_ROUTES, only against the staff member's own
     * assignment on that workspace. Routes not listed (opening the client /
     * workspace card, the client list) need no permission.
     */
    public const ROUTE_PERMISSIONS = [
        'GET api/workspaces/{workspace}/chat' => 'can_view_chat',
        'POST api/workspaces/{workspace}/chat/mark-read' => 'can_view_chat',
        'POST api/workspaces/{workspace}/chat' => 'can_send_chat',
        'PUT api/chat/{chatMessage}' => 'can_send_chat',
        'GET api/workspaces/{workspace}/approvals' => 'can_view_approvals',
        'POST api/workspaces/{workspace}/approvals' => 'can_request_approvals',
        'GET api/workspaces/{workspace}/meetings' => 'can_view_meetings',
        'POST api/meetings/{meeting}/enter' => 'can_join_meetings',
    ];

    /** Inside an allowed pattern, but never for a staff member. */
    private const DENIED_PATHS = [
        'api/notifications/send-fcm',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            if ($user->isAssistant() && ! config('team.assistants_enabled')) {
                abort(403, 'ميزة المساعدين متوقفة حاليًا.');
            }

            if ($user->isStaffMember()) {
                abort_unless($user->isActive(), 403, 'هذا الحساب متوقف حاليًا.');
                abort_unless($this->allowed($request), 403, 'غير مصرح لك بالوصول لهذه البيانات');
                abort_unless($this->permitted($request, $user), 403, 'غير مصرح لك بهذا الإجراء مع هذا العميل');
            }
        }

        return $next($request);
    }

    private function allowed(Request $request): bool
    {
        if ($request->is(self::ALLOWED_PATHS) && ! $request->is(self::DENIED_PATHS)) {
            return true;
        }

        $route = $request->route();
        if (! $route) {
            return false;
        }

        $method = $request->method() === 'HEAD' ? 'GET' : $request->method();

        return in_array($method . ' ' . $route->uri(), self::ALLOWED_ROUTES, true);
    }

    /**
     * The route's per-client permission (if it has one) must be held by this
     * staff member on the workspace the request targets. A request for a
     * workspace they are not assigned to passes here and is refused by the
     * policies exactly as before.
     */
    private function permitted(Request $request, User $user): bool
    {
        $route = $request->route();
        if (! $route) {
            return true;
        }

        $method = $request->method() === 'HEAD' ? 'GET' : $request->method();
        $key = self::ROUTE_PERMISSIONS[$method . ' ' . $route->uri()] ?? null;
        if ($key === null) {
            return true;
        }

        $workspace = $this->workspaceOf($route);
        if (! $workspace || ! $workspace->hasStaffMember($user)) {
            return true;
        }

        return $workspace->staffCan($user, $key);
    }

    private function workspaceOf($route): ?\App\Models\Workspace
    {
        $ws = $route->parameter('workspace');
        if ($ws !== null) {
            return $ws instanceof \App\Models\Workspace ? $ws : \App\Models\Workspace::find($ws);
        }

        $msg = $route->parameter('chatMessage');
        if ($msg !== null) {
            $msg = $msg instanceof \App\Models\ChatMessage ? $msg : \App\Models\ChatMessage::find($msg);

            return $msg?->workspace;
        }

        $meeting = $route->parameter('meeting');
        if ($meeting !== null) {
            $meeting = $meeting instanceof \App\Models\Meeting ? $meeting : \App\Models\Meeting::find($meeting);

            return $meeting?->workspace;
        }

        return null;
    }
}
