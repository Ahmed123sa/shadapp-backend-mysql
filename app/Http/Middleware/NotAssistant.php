<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An explicit wall for everything an assistant must never reach — payments,
 * finance, reports, client creation/archiving/transfer, workspace creation,
 * team and settings management — whatever permissions the manager picked.
 * It is deliberately a separate, visible line on the route instead of
 * relying on each policy happening to refuse.
 */
class NotAssistant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->isAssistant()) {
            abort(403, 'غير مصرح لك بالوصول لهذه البيانات');
        }

        return $next($request);
    }
}
