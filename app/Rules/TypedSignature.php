<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a typed (non-image) signature string.
 *
 * Signature columns double as plain text names and stored `/storage/signatures/...`
 * image paths (see App\Support\SignatureValue). Allowing arbitrary paths or URLs
 * in the typed-signature branch let an authenticated caller store a path like
 * `/storage/contracts/contract-1-signed.pdf` and receive a signed download URL
 * for another client's files in `signature_url`.
 */
class TypedSignature implements ValidationRule
{
    public const MAX_LENGTH = 200;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('التوقيع النصي غير صالح.');
            return;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            $fail('التوقيع النصي لا يمكن أن يكون فارغًا.');
            return;
        }

        if (mb_strlen($trimmed, 'UTF-8') > self::MAX_LENGTH) {
            $fail('التوقيع النصي طويل جدًا.');
            return;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $trimmed) === 1) {
            $fail('التوقيع النصي يحتوي على أحرف غير مسموح بها.');
            return;
        }

        if (
            str_starts_with($trimmed, '/')
            || str_starts_with($trimmed, '\\')
            || preg_match('/^https?(?::|\/\/|$)/i', $trimmed) === 1
            || preg_match('/^[a-z][a-z0-9+.-]*:/i', $trimmed) === 1
            || str_contains($trimmed, '://')
            || stripos($trimmed, '/storage/') !== false
            || stripos($trimmed, 'storage/') === 0
            || str_contains($trimmed, '..')
        ) {
            $fail('التوقيع النصي يجب أن يكون اسمًا نصيًا فقط بدون روابط أو مسارات.');
        }
    }
}
