<?php

namespace Tests\Feature;

use App\Support\FileUrl;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Mirrors tests/Feature/SignedFileUrlStabilityTest.php from shadapp-backend
 * (Postgres) verbatim.
 *
 * Signed file URLs have to be stable within a window, not merely valid.
 *
 * FileUrl::sign() is called from model accessors, so it runs on every read of
 * every *_url column. Signing with a raw now()->addMinutes() made the expiry,
 * and therefore the signature, and therefore the whole URL string, different
 * on every single read.
 *
 * Nothing about that is insecure — but every image cache downstream keys on
 * the URL string. Flutter's NetworkImage and the browser both treated a
 * re-signed avatar as a brand new image and re-downloaded it, so avatars in
 * the mobile chat list blinked out and faded back in on every refresh. That
 * is the bug these tests exist to keep fixed.
 */
class SignedFileUrlStabilityTest extends TestCase
{
    private function freeze(string $time): void
    {
        Carbon::setTestNow(Carbon::parse($time));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_same_path_signs_identically_twice_in_a_row(): void
    {
        $this->freeze('2026-09-18 10:00:00');

        $first = FileUrl::sign('/storage/avatars/a.png');
        $second = FileUrl::sign('/storage/avatars/a.png');

        $this->assertSame($first, $second);
    }

    public function test_the_url_does_not_change_within_the_rounding_window(): void
    {
        config(['filesystems.signed_url_window_minutes' => 15]);

        $this->freeze('2026-09-18 10:00:00');
        $atStart = FileUrl::sign('/storage/avatars/a.png');

        // Anywhere inside the same 15-minute window the expiry rounds to the
        // same boundary, so the signature — and the cache key — hold still.
        $this->freeze('2026-09-18 10:14:59');
        $nearEnd = FileUrl::sign('/storage/avatars/a.png');

        $this->assertSame($atStart, $nearEnd);
    }

    public function test_the_url_does_change_once_the_window_rolls_over(): void
    {
        config(['filesystems.signed_url_window_minutes' => 15]);

        $this->freeze('2026-09-18 10:00:00');
        $before = FileUrl::sign('/storage/avatars/a.png');

        $this->freeze('2026-09-18 10:16:00');
        $after = FileUrl::sign('/storage/avatars/a.png');

        // Still rotating, just on a schedule instead of per-read: a copied
        // link cannot live forever.
        $this->assertNotSame($before, $after);
    }

    public function test_different_paths_still_sign_differently(): void
    {
        $this->freeze('2026-09-18 10:00:00');

        $this->assertNotSame(
            FileUrl::sign('/storage/avatars/a.png'),
            FileUrl::sign('/storage/avatars/b.png')
        );
    }

    public function test_the_signature_is_still_valid_and_lasts_at_least_the_ttl(): void
    {
        config(['filesystems.signed_url_ttl_minutes' => 120]);
        config(['filesystems.signed_url_window_minutes' => 15]);

        $this->freeze('2026-09-18 10:00:00');
        $url = FileUrl::sign('/storage/avatars/a.png');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $expires = (int) ($query['expires'] ?? 0);

        // Rounding is upward, so the link never expires sooner than the
        // configured TTL — only later, by at most one window.
        $this->assertGreaterThanOrEqual(Carbon::parse('2026-09-18 12:00:00')->getTimestamp(), $expires);
        $this->assertLessThanOrEqual(Carbon::parse('2026-09-18 12:15:00')->getTimestamp(), $expires);
    }

    public function test_values_that_are_not_our_storage_urls_are_still_returned_untouched(): void
    {
        $this->freeze('2026-09-18 10:00:00');

        $base64 = 'data:image/png;base64,iVBORw0KGgo=';

        $this->assertSame($base64, FileUrl::sign($base64));
        $this->assertNull(FileUrl::sign(null));
    }
}
