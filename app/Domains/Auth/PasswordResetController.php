<?php

namespace App\Domains\Auth;

use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Self-service password reset for staff Users and Clients.
 *
 * SubUsers are deliberately excluded: they are created and managed by their
 * parent Client, who can already reset their password directly, so there is
 * no account here that can get permanently locked out.
 *
 * The two account types use separate brokers backed by separate token tables
 * (see config/auth.php) — a token issued for one is not valid for the other.
 */
class PasswordResetController extends Controller
{
    /**
     * Note on account enumeration.
     *
     * This endpoint deliberately tells the caller when an address has no
     * account. That is a conscious trade-off, not an oversight: accounts here
     * are created by managers rather than self-registered, so the only
     * realistic way a real user lands on the failure path is a typo — and
     * answering "sent!" to a typo leaves them waiting for an email that will
     * never arrive.
     *
     * The cost is that the endpoint can be used to test whether a given
     * address is registered. What keeps that from being useful at scale is
     * the rate limit on these routes (see routes/api.php), which is set
     * deliberately low — tightening or removing it changes the security
     * posture of this endpoint, so treat the two as a pair.
     */
    private function sentResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'تم إرسال الرابط لإيميلك. افتحه واختر كلمة مرور جديدة.',
        ]);
    }

    private function unknownEmailResponse(): JsonResponse
    {
        // 422 with an `errors` bag so both clients can surface it the same
        // way they surface any other field-level validation failure.
        return response()->json([
            'message' => 'الإيميل ده مش مسجل عندنا. اتأكد من كتابته أو تواصل مع مدير حسابك.',
            'errors' => ['email' => ['الإيميل ده مش مسجل عندنا.']],
        ], 422);
    }

    public function forgotStaff(Request $request): JsonResponse
    {
        return $this->sendLink($request, 'users');
    }

    public function forgotClient(Request $request): JsonResponse
    {
        return $this->sendLink($request, 'clients');
    }

    public function resetStaff(Request $request): JsonResponse
    {
        return $this->reset($request, 'users');
    }

    public function resetClient(Request $request): JsonResponse
    {
        return $this->reset($request, 'clients');
    }

    private function sendLink(Request $request, string $broker): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        $status = Password::broker($broker)->sendResetLink(
            ['email' => $request->email]
        );

        if ($status === Password::INVALID_USER) {
            return $this->unknownEmailResponse();
        }

        // RESET_THROTTLED means a link was already sent within the broker's
        // own cooldown window. The account clearly exists, so report success:
        // the user already has a working link in their inbox and telling them
        // "too many requests" would just push them to keep retrying.
        if (!in_array($status, [Password::RESET_LINK_SENT, Password::RESET_THROTTLED], true)) {
            return response()->json(['message' => 'تعذر إرسال رابط إعادة التعيين. حاول مرة أخرى.'], 500);
        }

        return $this->sentResponse();
    }

    private function reset(Request $request, string $broker): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $status = Password::broker($broker)->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, string $password) use ($request) {
                $fields = ['password' => $password];

                // Laravel's stock reset flow always rotates remember_token,
                // but only `users` has that column — `clients` does not, and
                // adding it would mean carrying a column this API never reads
                // (authentication here is Sanctum bearer tokens, not
                // "remember me" session cookies). The model was just loaded
                // from the database, so its attribute keys are exactly the
                // table's columns; rotate the token only where one exists.
                $rememberField = $user->getRememberTokenName();
                if ($rememberField && array_key_exists($rememberField, $user->getAttributes())) {
                    $fields[$rememberField] = Str::random(60);
                }

                $user->forceFill($fields)->save();

                // This is the real session invalidation for this app. Any
                // session opened with the old password is now suspect — if
                // the reset was triggered because the account was
                // compromised, leaving existing tokens alive would defeat the
                // whole point of resetting.
                $user->tokens()->delete();

                AuditLog::create([
                    'auditable_type' => get_class($user),
                    'auditable_id' => $user->id,
                    'user_id' => $user instanceof \App\Models\User ? $user->id : null,
                    'action' => 'password.reset',
                    'ip_address' => $request->ip(),
                ]);
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            // Covers both an unknown email and a bad/expired token. Kept as one
            // message on purpose: distinguishing them would reveal whether the
            // address is registered.
            return response()->json([
                'message' => 'رابط إعادة التعيين غير صالح أو منتهي الصلاحية. اطلب رابطاً جديداً.',
            ], 422);
        }

        return response()->json(['message' => 'تم تغيير كلمة المرور بنجاح.']);
    }
}
