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
            }
        }

        return $next($request);
    }

    private function allowed(Request $request): bool
    {
        return $request->is(self::ALLOWED_PATHS) && ! $request->is(self::DENIED_PATHS);
    }
}
