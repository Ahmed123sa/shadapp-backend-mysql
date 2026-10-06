<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level gate for the permissions a manager gives an assistant
 * (User::ASSISTANT_PERMISSION_KEYS). Same idea as subuser.can: only an
 * assistant is limited — managers, super admins, clients and sub-users pass
 * straight through (they have their own rules).
 */
class RequireAssistantPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->isAssistant() && ! $user->assistantCan($permission)) {
            abort(403, 'ليس لديك صلاحية القيام بهذا الإجراء');
        }

        return $next($request);
    }
}
