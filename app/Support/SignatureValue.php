<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * A `signature_data` (or `signature`) column stores one of two things: a typed
 * name (plain text) or a path/URL to an uploaded image (from a file upload or a
 * hand-drawn signature rendered client-side). Several places need to tell
 * these apart before rendering — contract PDFs, approval certificates, the
 * dashboard's "use saved signature" flow. Centralizing it here ensures only
 * real signature images under `/storage/signatures/` are treated as images,
 * preventing arbitrary `/storage/...` path values from turning into signed URLs
 * or embedded files.
 */
class SignatureValue
{
    private const ALLOWED_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp'];

    public static function isImage(?string $value): bool
    {
        return self::relativeStoragePath($value) !== null;
    }

    /**
     * Absolute filesystem path for an image signature, for embedding into a
     * PDF (mPDF needs a real path, not a URL). Returns null if the value isn't
     * an image signature, resolves outside the signatures directory, or the
     * file no longer exists on disk.
     */
    public static function diskPath(?string $value): ?string
    {
        $relative = self::relativeStoragePath($value);
        if ($relative === null) {
            return null;
        }

        $disk = Storage::disk('public');
        $signaturesRoot = realpath($disk->path('signatures'));
        $resolved = realpath($disk->path($relative));

        if ($signaturesRoot === false || $resolved === false) {
            return null;
        }

        return str_starts_with($resolved, $signaturesRoot . DIRECTORY_SEPARATOR) ? $resolved : null;
    }

    /**
     * Extracts a validated `signatures/<filename>` relative path from a stored
     * signature value, or returns null when the value is typed text or an
     * untrusted/non-signature path.
     */
    public static function relativeStoragePath(?string $value): ?string
    {
        if ($value === null || $value === '' || preg_match('/[?#\x00-\x1F\x7F]/', $value) === 1) {
            return null;
        }

        if (preg_match('#^https?://#i', $value) === 1) {
            $parts = parse_url($value);
            if (! is_array($parts) || empty($parts['host']) || empty($parts['path'])) {
                return null;
            }
            if (isset($parts['query']) || isset($parts['fragment'])) {
                return null;
            }

            $host = strtolower($parts['host']);
            $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
            $allowedHosts = array_filter([$appHost]);
            if (app()->environment(['local', 'testing'])) {
                $allowedHosts = array_merge($allowedHosts, ['localhost', '127.0.0.1']);
            }
            if (! in_array($host, $allowedHosts, true)) {
                return null;
            }

            $path = $parts['path'];
        } elseif (str_starts_with($value, '/storage/')) {
            $path = $value;
        } else {
            return null;
        }

        if (preg_match('#^/storage/signatures/([A-Za-z0-9._-]+)$#', $path, $matches) !== 1) {
            return null;
        }

        $filename = $matches[1];
        if (str_starts_with($filename, '.') || str_contains($filename, '..')) {
            return null;
        }

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (! in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            return null;
        }

        return 'signatures/' . $filename;
    }
}
