<?php

namespace App\Domains\Auth;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * DELETE /api/auth/account — in-app account deletion (App Store Guideline
 * 5.1.1(v)). One route for every account type; the account is whichever of
 * User / Client / SubUser the bearer token belongs to. What "delete" means per
 * type is in AccountDeletionService.
 *
 * Response contract (agreed with the mobile app):
 *  - 200            deleted
 *  - 422 password   missing/wrong password. Never 401: the app treats any 401
 *                   as "session expired" and jumps to the login screen.
 *  - 403 message    not allowed (super admin) or the feature is switched off
 */
class AccountDeletionController extends Controller
{
    public function destroy(Request $request, AccountDeletionService $service): JsonResponse
    {
        if (! config('account_deletion.enabled')) {
            return response()->json(['message' => 'حذف الحساب غير متاح حاليًا.'], 403);
        }

        $actor = $request->user();

        // The only account that runs the system; deleting it would leave the
        // company with nobody able to manage or recover it.
        if ($actor instanceof User && $actor->isSuperAdmin()) {
            return response()->json(['message' => 'لا يمكن حذف حساب الأدمن الرئيسي من التطبيق.'], 403);
        }

        $request->validate(['password' => 'required|string']);

        if (! Hash::check($request->password, $actor->password)) {
            throw ValidationException::withMessages(['password' => ['الباسورد غير صحيح.']]);
        }

        $service->delete($actor, $request);

        return response()->json(['message' => 'تم حذف الحساب.']);
    }
}
