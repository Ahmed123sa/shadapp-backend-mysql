<?php

namespace App\Support;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Per-account limit on FAILED sign-ins.
 *
 * Only a wrong password (or an unknown email) counts. A successful sign-in
 * clears the counters, so someone who signs in correctly many times (several
 * devices, testing, logging out and in) is never locked out, while guessing
 * passwords is still capped:
 *
 *   - 5 failures for one email from one IP  -> wait 1 minute
 *   - 10 failures for one email from any IP -> wait 10 minutes
 *
 * While locked, even the right password is refused, so the lock cannot be
 * used to keep guessing. The plain per-IP cap (every request, any email)
 * stays on the route as `throttle:login-staff` / `throttle:login-client`.
 *
 * Staff and client endpoints keep separate counters ($bucket), because the
 * mobile app tries the staff endpoint first for every sign-in.
 */
class LoginThrottle
{
    public const EMAIL_IP_MAX = 5;
    public const EMAIL_IP_DECAY_SECONDS = 60;
    public const EMAIL_MAX = 10;
    public const EMAIL_DECAY_SECONDS = 600;

    /** @return array<string, array{0:int,1:int}> key => [max attempts, decay seconds] */
    private static function keys(Request $request, string $bucket): array
    {
        $email = strtolower(trim((string) $request->input('email', '')));
        $ip = (string) $request->ip();

        return [
            "login-fail:{$bucket}:email-ip:{$email}|{$ip}" => [self::EMAIL_IP_MAX, self::EMAIL_IP_DECAY_SECONDS],
            "login-fail:{$bucket}:email:{$email}" => [self::EMAIL_MAX, self::EMAIL_DECAY_SECONDS],
        ];
    }

    /** Refuse with 429 while any counter for this email is over its limit. */
    public static function ensureNotLocked(Request $request, string $bucket): void
    {
        $wait = 0;
        foreach (self::keys($request, $bucket) as $key => [$max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $wait = max($wait, RateLimiter::availableIn($key));
            }
        }

        if ($wait > 0) {
            $minutes = max(1, (int) ceil($wait / 60));

            throw new HttpResponseException(response()->json([
                'message' => "محاولات دخول غلط كتير. استنى {$minutes} دقيقة وجرّب تاني. Too many failed sign-in attempts. Try again in {$minutes} minute(s).",
                'code' => 'too_many_login_attempts',
                'retry_after' => $wait,
            ], 429, ['Retry-After' => (string) $wait]));
        }
    }

    /** Count one failed attempt. */
    public static function fail(Request $request, string $bucket): void
    {
        foreach (self::keys($request, $bucket) as $key => [, $decay]) {
            RateLimiter::hit($key, $decay);
        }
    }

    /** A successful sign-in wipes this email's failure counters. */
    public static function clear(Request $request, string $bucket): void
    {
        foreach (array_keys(self::keys($request, $bucket)) as $key) {
            RateLimiter::clear($key);
        }
    }
}
