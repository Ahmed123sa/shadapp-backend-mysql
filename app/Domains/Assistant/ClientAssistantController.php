<?php

namespace App\Domains\Assistant;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\SubUser;
use App\Models\Workspace;
use App\Support\AssistantRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The client assistant (CLIENT_ASSISTANT_PLAN.md ٥.٤). Read only: it never
 * changes anything, it only turns the client's own data into answers.
 *
 * The route sits in the dual-auth group, so ScopeWorkspace has already checked
 * that the workspace is the caller's. Staff are refused here: the assistant is
 * for the client and the client's own assistants only.
 */
class ClientAssistantController extends Controller
{
    public function show(Request $request, Workspace $workspace): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof Client || $user instanceof SubUser, 403, 'غير مصرح');

        if (! config('assistant.enabled')) {
            return response()->json(['enabled' => false]);
        }

        return response()->json(['enabled' => true] + (new AssistantRenderer($workspace, $user))->build());
    }
}
