<?php

namespace App\Support;

/**
 * Single source of truth for what may be uploaded, and how large.
 *
 * Before this existed each upload endpoint spelled its own rules out inline,
 * and they had drifted badly: chat attachments and approval files accepted
 * ANY extension up to 100MB (so a .php/.exe/.svg could be stored on the
 * public disk and handed back as a working URL), while the two payment-proof
 * paths disagreed with each other about both extensions and size. Route new
 * upload endpoints through these constants rather than writing rules by hand.
 *
 * Note on `mimes:` — Laravel validates the file's *guessed* MIME type against
 * the extension list, it does not simply trust the client-supplied filename,
 * so this is a real content check rather than a cosmetic one.
 */
class UploadRules
{
    /** Documents + images: the general-purpose set for attachments. */
    public const DOCUMENT_MIMES = 'pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip';

    /** Proof-of-payment and similar evidence: images and PDF only. */
    public const PROOF_MIMES = 'jpg,jpeg,png,webp,pdf';

    /**
     * Avatars/signatures. SVG is deliberately excluded — it can carry inline
     * <script>, and these are rendered back to other users.
     */
    public const IMAGE_MIMES = 'jpg,jpeg,png,webp';

    /** Kilobytes, matching Laravel's `max:` unit. */
    public const MAX_DOCUMENT_KB = 25600;  // 25 MB
    public const MAX_PROOF_KB = 10240;     // 10 MB
    public const MAX_IMAGE_KB = 5120;      // 5 MB

    public static function document(bool $required = false): string
    {
        return self::build($required, self::DOCUMENT_MIMES, self::MAX_DOCUMENT_KB);
    }

    public static function proof(bool $required = false): string
    {
        return self::build($required, self::PROOF_MIMES, self::MAX_PROOF_KB);
    }

    public static function image(bool $required = false): string
    {
        return ($required ? 'required' : 'nullable')
            .'|image|mimes:'.self::IMAGE_MIMES.'|max:'.self::MAX_IMAGE_KB;
    }

    private static function build(bool $required, string $mimes, int $maxKb): string
    {
        return ($required ? 'required' : 'nullable')."|file|mimes:{$mimes}|max:{$maxKb}";
    }
}
