<?php

namespace Tests\Feature;

use App\Services\OffsiteBackupCopy;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Mirrors tests/Feature/OffsiteBackupCopyTest.php from shadapp-backend
 * (Postgres) verbatim.
 *
 * Covers App\Services\OffsiteBackupCopy, the half of db:backup that puts a
 * copy of the archive somewhere the local disk cannot take with it.
 *
 * Driven directly rather than through db:backup, for the same reason
 * RestoreDatabaseTest drives db:restore with --files-only: producing a real
 * archive needs mysqldump against a mysql/pgsql connection, which this
 * sqlite suite cannot give it. Everything that is actually this class's own
 * behaviour — refusing an unmounted destination, verifying the copy,
 * deleting a copy that does not verify, retention — is exercised here.
 */
class OffsiteBackupCopyTest extends TestCase
{
    private string $source;

    private string $destination;

    protected function setUp(): void
    {
        parent::setUp();

        $base = storage_path('app/offsite-test-'.uniqid());
        $this->source = $base.DIRECTORY_SEPARATOR.'local';
        $this->destination = $base.DIRECTORY_SEPARATOR.'offsite';

        File::ensureDirectoryExists($this->source);
        File::ensureDirectoryExists($this->destination);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(dirname($this->source));

        parent::tearDown();
    }

    private function makeArchive(string $name, string $contents = 'archive-bytes'): string
    {
        $path = $this->source.DIRECTORY_SEPARATOR.$name;
        File::put($path, $contents);

        return $path;
    }

    public function test_it_is_not_configured_when_no_path_is_set(): void
    {
        config(['database.backup_offsite_path' => '']);

        $this->assertFalse(OffsiteBackupCopy::fromConfig()->isConfigured());
    }

    public function test_it_reads_the_destination_from_config(): void
    {
        config(['database.backup_offsite_path' => $this->destination]);

        $copier = OffsiteBackupCopy::fromConfig();

        $this->assertTrue($copier->isConfigured());
        $this->assertSame($this->destination, $copier->destination());
    }

    public function test_it_copies_the_archive_to_the_destination(): void
    {
        $archive = $this->makeArchive('shadapp-2026-09-16_120000.zip');

        $target = (new OffsiteBackupCopy($this->destination, 30))->copy($archive);

        $this->assertFileExists($target);
        $this->assertSame('archive-bytes', File::get($target));
        $this->assertSame('shadapp-2026-09-16_120000.zip', basename($target));
    }

    public function test_it_refuses_a_destination_that_is_not_there(): void
    {
        // Stands in for an unplugged external disk or an unmounted share.
        // The directory is never created: doing so would put the "off-site"
        // copies on the local disk, which is the failure this whole class
        // exists to prevent, arrived at without anyone noticing.
        $missing = $this->destination.DIRECTORY_SEPARATOR.'not-mounted';
        $archive = $this->makeArchive('shadapp-2026-09-16_120000.zip');

        try {
            (new OffsiteBackupCopy($missing, 30))->copy($archive);
            $this->fail('An unreachable destination should have been refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('is not reachable', $e->getMessage());
        }

        // The assertion that matters, and the reason this is not written with
        // expectException: nothing was created at the missing path. Silently
        // creating it is how "off-site" copies end up on the local disk.
        $this->assertDirectoryDoesNotExist($missing);
    }

    public function test_a_copy_that_does_not_match_is_rejected_and_deleted(): void
    {
        $archive = $this->makeArchive('shadapp-2026-09-16_120000.zip', 'the-real-archive');

        // Substitutes a half-written copy for the real one, which is the
        // failure that matters: the destination filled up or the connection
        // dropped mid-write. The rule being proven is that a target whose
        // contents do not match the source is never left behind looking like
        // a valid backup.
        $copier = new class($this->destination, 30) extends OffsiteBackupCopy
        {
            protected function write(string $source, string $target): bool
            {
                File::put($target, 'truncated');

                return true;
            }
        };

        $target = $this->destination.DIRECTORY_SEPARATOR.'shadapp-2026-09-16_120000.zip';

        try {
            $copier->copy($archive);
            $this->fail('A mismatched copy should have been rejected.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('wrong size', $e->getMessage());
        }

        $this->assertFileDoesNotExist($target, 'A copy that failed verification must not be left on disk.');
    }

    public function test_prune_keeps_the_newest_archives(): void
    {
        foreach (['a', 'b', 'c', 'd'] as $i => $name) {
            $path = $this->destination.DIRECTORY_SEPARATOR."shadapp-{$name}.zip";
            File::put($path, 'x');
            touch($path, time() - (100 * (4 - $i)));
        }

        $deleted = (new OffsiteBackupCopy($this->destination, 2))->prune();

        $this->assertSame(2, $deleted);
        $this->assertFileExists($this->destination.DIRECTORY_SEPARATOR.'shadapp-d.zip');
        $this->assertFileExists($this->destination.DIRECTORY_SEPARATOR.'shadapp-c.zip');
        $this->assertFileDoesNotExist($this->destination.DIRECTORY_SEPARATOR.'shadapp-a.zip');
        $this->assertFileDoesNotExist($this->destination.DIRECTORY_SEPARATOR.'shadapp-b.zip');
    }

    public function test_prune_leaves_non_archive_files_alone(): void
    {
        File::put($this->destination.DIRECTORY_SEPARATOR.'notes.txt', 'not a backup');
        File::put($this->destination.DIRECTORY_SEPARATOR.'shadapp-a.zip', 'x');

        (new OffsiteBackupCopy($this->destination, 0))->prune();

        $this->assertFileExists($this->destination.DIRECTORY_SEPARATOR.'notes.txt');
    }
}
