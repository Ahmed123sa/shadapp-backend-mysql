<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces tenant isolation on every route that binds a {workspace} route
 * parameter. This is a defense that must sit in front of the controller —
 * Policies alone are not sufficient here because several existing Policy
 * `viewAny()` methods only check the caller's *type*, not their relationship
 * to the specific workspace being requested (see ContractPolicy::viewAny).
 *
 * Also verifies that any secondary route-model-bound resource (payment,
 * file, meeting, contract, approval, documentDefinition) actually belongs
 * to the {workspace} in the same URL, closing a related mismatch-ID leak.
 */
class ScopeWorkspace
{
    /**
     * Route parameter names of child resources that carry a workspace_id
     * column and must be confirmed to belong to the bound {workspace}.
     */
    private const CHILD_RESOURCE_PARAMS = [
        'payment',
        'file',
        'documentDefinition',
        'meeting',
        'contract',
        'approval',
        'chatMessage',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $workspace = $request->route('workspace');

        if (! $workspace instanceof Workspace) {
            return $next($request);
        }

        $user = $request->user();

        abort_if(! $user, 401, 'غير مصرح');
        abort_unless($workspace->canBeAccessedBy($user), 403, 'غير مصرح لك بالوصول إلى مساحة العمل هذه');

        foreach (self::CHILD_RESOURCE_PARAMS as $param) {
            $child = $request->route($param);

            if ($child !== null && isset($child->workspace_id) && (int) $child->workspace_id !== (int) $workspace->id) {
                // 404, not 403 — avoid confirming the resource exists in another tenant.
                abort(404);
            }
        }

        return $next($request);
    }
}
