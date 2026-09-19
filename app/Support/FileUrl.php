<?php

namespace App\Support;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Turns a stored `Storage::url($path)` value (e.g.
 * "http://host/storage/contracts/foo.pdf") into a short-lived signed URL
 * through the `files.serve` route, instead of a permanent, guessable one.
 *
 * This is applied at READ time (model accessors), not write time: several
 * *_url columns are persisted once and read back indefinitely (Contract's
 * pdf_url, FileEntry's file_url, ...). Signing at write time would bake an
 * expiry into the stored value and the link would go dead forever once that
 * expiry passed. Signing fresh on every read means the link handed to the
 * frontend is always valid for a full TTL from *now*, no matter how old the
 * underlying file is.
 *
 * Only values that actually look like one of our own `/storage/...` URLs are
 * transformed — anything else (a base64 signature, an external link, an
 * already-signed value, null) is returned untouched. This keeps the helper
 * safe to apply broadly without needing to reason about every possible
 * shape a column might hold.
 */
class FileUrl
{
    public static function sign(?string $value, ?int $ttlMinutes = null): ?string
    {
        if (! $value) {
            return $value;
        }

        $path = static::extractStoragePath($value);
        if ($path === null) {
            return $value;
        }

        $ttl = $ttlMinutes ?? (int) config('filesystems.signed_url_ttl_minutes', 120);

        return URL::temporarySignedRoute('files.serve', static::expiryFor($ttl), ['path' => $path]);
    }

    /**
     * The expiry timestamp, rounded up to a fixed clock boundary.
     *
     * Signing with a raw `now()->addMinutes($ttl)` makes the expiry — and so
     * the signature, and so the whole URL — different on every single read.
     * That quietly breaks every image cache downstream: Flutter's
     * NetworkImage and the browser both key their cache on the URL string, so
     * a freshly signed avatar is a cache miss every time the list it sits in
     * refreshes. The visible symptom is an avatar that blinks out and fades
     * back in on every poll, which is what sent us looking.
     *
     * Rounding the expiry up to the next whole window makes every read of the
     * same path inside that window produce a byte-identical URL, so the cache
     * hits and the image stays put. The cost is that a link lives somewhere
     * between $ttl and $ttl + window minutes rather than exactly $ttl — which
     * matters not at all for a bound that exists to stop an indefinitely
     * shareable link, not to expire one to the second.
     */
    private static function expiryFor(int $ttlMinutes): \DateTimeInterface
    {
        $window = max(1, (int) config('filesystems.signed_url_window_minutes', 15)) * 60;

        // Anchor on *now*, not on now+ttl. Rounding the expiry itself looks
        // equivalent but is not: the boundary then moves with the clock, so
        // two reads a second apart can straddle it and produce different
        // URLs — which is the whole problem this is meant to solve, and what
        // the first version of this method got wrong.
        //
        // Flooring the current time to its window and adding a full window
        // means every read inside one window lands on the same expiry, and
        // the link always outlives the configured TTL by up to one window.
        $windowStart = (int) (floor(now()->getTimestamp() / $window) * $window);

        return Date::createFromTimestamp($windowStart + $window + ($ttlMinutes * 60));
    }

    /**
     * @return array<int, string|null>
     */
    public static function signMany(?array $values, ?int $ttlMinutes = null): ?array
    {
        if ($values === null) {
            return null;
        }

        return array_map(fn ($v) => static::sign($v, $ttlMinutes), $values);
    }

    protected static function extractStoragePath(string $value): ?string
    {
        $urlPath = Str::contains($value, '://') ? (string) parse_url($value, PHP_URL_PATH) : $value;

        if (! Str::contains($urlPath, '/storage/')) {
            return null;
        }

        return ltrim(Str::after($urlPath, '/storage/'), '/');
    }
}
