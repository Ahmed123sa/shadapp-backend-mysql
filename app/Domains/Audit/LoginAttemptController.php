<?php

namespace App\Domains\Audit;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\LoginAttempt;
use App\Models\SubUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 20 Sept 2026 — read side of login_attempts. Without it the table is
 * write-only: the rows are collected correctly but nobody without direct
 * database access can read them, which leaves the support question this
 * was built for ("why can't this person log in") exactly as unanswerable
 * as it was before.
 *
 * The route carries `staff.only`, which is what actually keeps clients and
 * sub-users out — the group it sits in does not (see StaffOnly's docblock).
 * The scoping below runs *after* that and narrows staff against each other;
 * it is not the gate.
 */
class LoginAttemptController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = LoginAttempt::query();

        if ($request->filled('search')) {
            $query->where('email', 'like', '%' . $request->search . '%');
        }

        if ($request->filled('reason')) {
            $query->where('reason', $request->reason);
        }

        if ($request->filled('endpoint')) {
            $query->where('endpoint', $request->endpoint);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $user = $request->user();

        // An account manager sees only attempts against an email belonging
        // to one of their own clients, or to a sub-user of one. Matched as
        // subqueries rather than pluck()->toArray() because a super admin's
        // client list can be long and this runs on every page load.
        //
        // A side effect worth naming: this necessarily hides every
        // unknown_email row from an account manager, since an email that
        // matches no account matches no client of theirs either. That is
        // the right outcome — bot traffic and typos against addresses that
        // don't exist are a system-wide security concern for the super
        // admin, not something an account manager can act on — but it does
        // mean an AM's view is deliberately not the whole picture.
        if ($user->isAccountManager()) {
            $managedClientIds = Client::where('manager_id', $user->id)->select('id');

            $query->where(function ($q) use ($user, $managedClientIds) {
                $q->whereIn('email', Client::where('manager_id', $user->id)->select('email'))
                    ->orWhereIn('email', SubUser::whereIn('client_id', $managedClientIds)->select('email'));
            });
        }

        return response()->json([
            'attempts' => $query->latest()->paginate(25),
        ]);
    }
}
