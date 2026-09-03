<?php

namespace App\Support;

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

        return URL::temporarySignedRoute('files.serve', now()->addMinutes($ttl), ['path' => $path]);
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
