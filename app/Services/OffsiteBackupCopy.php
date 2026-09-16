<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

/**
 * Mirrors app/Services/OffsiteBackupCopy.php from shadapp-backend (Postgres)
 * verbatim — it copies a finished zip and never touches the database, so
 * nothing here is driver-specific.
 *
 * Copies a finished db:backup archive to a second location, and proves the
 * copy arrived intact before calling it done.
 *
 * A backup stored only on the disk it is protecting is not a backup, it is a
 * second file. The disk that dies takes both. So db:backup writes locally
 * (fast, always available) and then mirrors the archive here.
 *
 * Two rules do the real work:
 *
 * 1. The destination directory must already exist. It is never created.
 *    An unplugged external disk, an unmounted share or a typo in
 *    BACKUP_OFFSITE_PATH would otherwise end with a folder created on the
 *    local disk and archives piling up somewhere that is not off-site at
 *    all — the exact failure this class exists to prevent, arrived at
 *    silently. A missing directory is treated as "the destination is not
 *    there", which is the truth.
 *
 * 2. Every copy is verified by size and SHA-256 against the original, and a
 *    copy that does not match is deleted. Half a zip is not a backup, and a
 *    corrupt file that looks like one is worse than no file: it is the thing
 *    you reach for on the worst day, and it fails then instead of now.
 *    Hashing costs a second read of the archive; that is the cheapest
 *    insurance in this whole file.
 */
class OffsiteBackupCopy
{
    public function __construct(
        private readonly ?string $destination,
        private readonly int $keep,
    ) {}

    public static function fromConfig(): self
    {
        $path = trim((string) config('database.backup_offsite_path', ''));

        return new self(
            $path === '' ? null : $path,
            (int) config('database.backup_offsite_keep', 30),
        );
    }

    public function isConfigured(): bool
    {
        return $this->destination !== null;
    }

    public function destination(): ?string
    {
        return $this->destination;
    }

    /**
     * @return string the absolute path of the verified off-site copy
     *
     * @throws \RuntimeException if the destination is unreachable, the write
     *                           fails, or the copy does not match the original
     */
    public function copy(string $archivePath): string
    {
        if ($this->destination === null) {
            throw new \RuntimeException('No off-site destination is configured.');
        }

        if (! File::isDirectory($this->destination)) {
            throw new \RuntimeException(
                "Off-site destination [{$this->destination}] is not reachable. "
                .'If it is an external disk or a network share, it is probably not mounted. '
                .'The directory is never created automatically — see OffsiteBackupCopy.'
            );
        }

        $target = rtrim($this->destination, '/\\').DIRECTORY_SEPARATOR.basename($archivePath);

        if (! $this->write($archivePath, $target)) {
            throw new \RuntimeException("Could not write [{$target}]. Check free space and permissions.");
        }

        $this->verify($archivePath, $target);

        return $target;
    }

    /**
     * The actual write, separated purely so a test can substitute a
     * half-written copy and prove verify() catches and removes it. There is
     * no way to make a real copy() truncate on demand, and a verification
     * step nothing ever exercises is a comment, not a safeguard.
     */
    protected function write(string $source, string $target): bool
    {
        return @copy($source, $target);
    }

    /**
     * Keeps the newest $keep archives at the destination.
     *
     * Retained separately from the local --keep, and usually for longer: the
     * off-site copy is the one still standing when the local disk is gone, so
     * it is the one that needs to reach further back.
     *
     * @return int how many archives were deleted
     */
    public function prune(): int
    {
        if ($this->destination === null || $this->keep < 1 || ! File::isDirectory($this->destination)) {
            return 0;
        }

        $stale = collect(File::files($this->destination))
            ->filter(fn ($file) => str_ends_with($file->getFilename(), '.zip'))
            ->sortByDesc(fn ($file) => $file->getMTime())
            ->slice($this->keep);

        foreach ($stale as $file) {
            File::delete($file->getPathname());
        }

        return $stale->count();
    }

    private function verify(string $source, string $target): void
    {
        // The copy was just written; without this PHP may answer from a
        // cached stat and report the size the file had a moment ago.
        clearstatcache(true, $target);

        $sourceSize = File::size($source);
        $targetSize = File::exists($target) ? File::size($target) : -1;

        if ($sourceSize !== $targetSize) {
            @unlink($target);

            throw new \RuntimeException(
                "Off-site copy is the wrong size ({$targetSize} bytes of {$sourceSize}) — "
                .'the destination may be full or the connection dropped. The partial file was deleted.'
            );
        }

        if (hash_file('sha256', $source) !== hash_file('sha256', $target)) {
            @unlink($target);

            throw new \RuntimeException(
                'Off-site copy does not match the original archive. The corrupt file was deleted.'
            );
        }
    }
}
