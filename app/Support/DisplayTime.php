<?php

namespace App\Support;

use Carbon\Carbon;
use DateTimeInterface;

/**
 * Formats a stored (UTC) timestamp for text the server renders itself —
 * emails, push notification bodies, PDFs.
 *
 * The apps convert times to each viewer's own timezone, but server-rendered
 * text can't know the reader's timezone, so it uses one business timezone
 * (config('app.display_timezone'), Egypt by default; the tz database handles
 * Egypt's summer time). The application timezone itself stays UTC.
 */
class DisplayTime
{
    public static function format(?DateTimeInterface $date, string $format = 'Y-m-d H:i'): string
    {
        if ($date === null) {
            return '';
        }

        return Carbon::instance($date)
            ->copy()
            ->setTimezone(config('app.display_timezone', 'Africa/Cairo'))
            ->format($format);
    }
}
