<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * A `signature_data` (or `signature`) column stores one of two things: a typed
 * name (plain text) or a path/URL to an uploaded PNG (from a file upload or a
 * hand-drawn signature rendered client-side). Several places need to tell
 * these apart before rendering — contract PDFs, approval certificates, the
 * dashboard's "use saved signature" flow. That check was previously
 * copy-pasted at each call site, and one copy (the approval certificate) was
 * missing it entirely, so image signatures rendered as a raw file path in
 * plain text. Centralizing it here so there is exactly one place to get right.
 */
class SignatureValue
{
    public static function isImage(?string $value): bool
    {
        return $value !== null && $value !== ''
            && (str_starts_with($value, '/storage/') || str_starts_with($value, 'http'));
    }

    /**
     * Absolute filesystem path for an image signature, for embedding into a
     * PDF (mPDF needs a real path, not a URL). Returns null if the value isn't
     * an image signature or the file no longer exists on disk.
     */
    public static function diskPath(?string $value): ?string
    {
        if (! self::isImage($value)) {
            return null;
        }

        $relative = str_replace('/storage/', '', $value);
        $full = storage_path('app/public/' . $relative);

        return file_exists($full) ? $full : null;
    }
}
