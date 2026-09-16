<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Mirrors tests/Feature/RestoreDatabaseTest.php from shadapp-backend
 * (Postgres) verbatim.
 *
 * Covers App\Console\Commands\RestoreDatabase.
 *
 * The SQL half of a restore cannot run here: replaying a dump needs a real
 * mysql/psql client against a mysql/pgsql connection, and this suite runs on
 * sqlite :memory: — the same limitation DataExportTest documents for the
 * 'system' export scope. So these tests drive the command with --files-only,
 * which exercises everything that is actually this command's own logic:
 * archive resolution, validation, refusing an archive that isn't a db:backup
 * archive, the merge-don't-delete file rule, --fresh, and the orphan report.
 * The SQL replay itself is verified by the manual end-to-end drill described
 * in DATA_SAFETY_PLAN.md, because a test that mocks the restore tool would
 * prove only that the mock was called.
 */
class RestoreDatabaseTest extends TestCase
{
    use RefreshDatabase;

    private string $backupsDir;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->backupsDir = storage_path('app/backups');
        File::ensureDirectoryExists($this->backupsDir);
    }

    protected function tearDown(): void
    {
        foreach (File::glob($this->backupsDir.'/restore-test-*.zip') as $path) {
            File::delete($path);
        }

        parent::tearDown();
    }

    /**
     * @param  array<string, string>  $files  archive-relative path => contents
     */
    private function makeArchive(string $name, array $files): string
    {
        $path = $this->backupsDir.DIRECTORY_SEPARATOR.$name;

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true);

        foreach ($files as $relative => $contents) {
            $zip->addFromString($relative, $contents);
        }

        $zip->close();

        return $path;
    }

    public function test_it_fails_when_the_archive_does_not_exist(): void
    {
        $this->artisan('db:restore', ['archive' => 'restore-test-nope.zip', '--force' => true])
            ->assertExitCode(1);
    }

    public function test_it_refuses_an_archive_with_no_database_dump(): void
    {
        // A scoped data export (exports/client/...) looks like a zip but holds
        // JSON, not a dump — restoring one is a different feature entirely and
        // the command must say so rather than half-apply it.
        $this->makeArchive('restore-test-export.zip', ['client.json' => '{"id":1}']);

        $this->artisan('db:restore', ['archive' => 'restore-test-export.zip', '--force' => true, '--skip-snapshot' => true])
            ->expectsOutputToContain('no database.sql')
            ->assertExitCode(1);
    }

    public function test_database_only_and_files_only_cannot_be_combined(): void
    {
        $this->artisan('db:restore', ['--database-only' => true, '--files-only' => true, '--force' => true])
            ->assertExitCode(1);
    }

    public function test_it_rejects_an_unknown_connection_name(): void
    {
        $this->makeArchive('restore-test-conn.zip', ['database.sql' => 'SELECT 1;']);

        $this->artisan('db:restore', [
            'archive' => 'restore-test-conn.zip',
            '--connection' => 'restore_targt',
            '--force' => true,
            '--skip-snapshot' => true,
        ])
            ->expectsOutputToContain('No connection named [restore_targt]')
            ->assertExitCode(1);
    }

    public function test_it_refuses_a_connection_with_no_database_name(): void
    {
        // What restore_target looks like before DB_RESTORE_DATABASE is set.
        // Refusing here is the point: the command drops every table it finds,
        // so it must never fall back to a guessed database name.
        config(['database.connections.restore_target.database' => '']);

        $this->makeArchive('restore-test-nodb.zip', ['database.sql' => 'SELECT 1;']);

        $this->artisan('db:restore', [
            'archive' => 'restore-test-nodb.zip',
            '--connection' => 'restore_target',
            '--force' => true,
            '--skip-snapshot' => true,
        ])
            ->expectsOutputToContain('no database name configured')
            ->assertExitCode(1);
    }

    public function test_the_confirmation_names_the_connection_it_will_restore_into(): void
    {
        $this->makeArchive('restore-test-named.zip', ['database.sql' => 'SELECT 1;']);

        // --connection=mysql rather than rebinding database.default: the
        // driver check needs a target it accepts (the suite's default is
        // sqlite, which db:restore rejects before ever reaching the prompt),
        // and repointing the *default* would send RefreshDatabase's teardown
        // rollback at a connection it never opened. Nothing here actually
        // connects — answering 'no' returns before the first query.
        $this->artisan('db:restore', [
            'archive' => 'restore-test-named.zip',
            '--connection' => 'mysql',
        ])
            ->expectsOutputToContain('connection: mysql')
            ->expectsConfirmation('This overwrites existing data. Continue?', 'no')
            ->assertExitCode(1);
    }

    public function test_declining_the_confirmation_changes_nothing(): void
    {
        $this->makeArchive('restore-test-decline.zip', [
            'database.sql' => 'SELECT 1;',
            'files/contracts/from-archive.pdf' => 'archive-copy',
        ]);

        $this->artisan('db:restore', ['archive' => 'restore-test-decline.zip', '--files-only' => true])
            ->expectsConfirmation('This overwrites existing data. Continue?', 'no')
            ->assertExitCode(1);

        Storage::disk('public')->assertMissing('contracts/from-archive.pdf');
    }

    public function test_it_extracts_the_archives_files_and_overwrites_matching_paths(): void
    {
        Storage::disk('public')->put('contracts/shared.pdf', 'on-disk-version');

        $this->makeArchive('restore-test-merge.zip', [
            'database.sql' => 'SELECT 1;',
            'files/contracts/shared.pdf' => 'archive-version',
            'files/contracts/only-in-archive.pdf' => 'archive-only',
        ]);

        $this->artisan('db:restore', [
            'archive' => 'restore-test-merge.zip',
            '--files-only' => true,
            '--force' => true,
            '--skip-snapshot' => true,
        ])->assertExitCode(0);

        // Same path in both: the archive's copy wins, because that is the one
        // the restored database's rows point at.
        $this->assertSame('archive-version', Storage::disk('public')->get('contracts/shared.pdf'));
        $this->assertSame('archive-only', Storage::disk('public')->get('contracts/only-in-archive.pdf'));
    }

    public function test_a_file_missing_from_the_archive_is_kept_not_deleted(): void
    {
        Storage::disk('public')->put('chat/uploaded-after-the-backup.png', 'keep-me');

        $this->makeArchive('restore-test-keep.zip', [
            'database.sql' => 'SELECT 1;',
            'files/contracts/from-archive.pdf' => 'archive-copy',
        ]);

        $this->artisan('db:restore', [
            'archive' => 'restore-test-keep.zip',
            '--files-only' => true,
            '--force' => true,
            '--skip-snapshot' => true,
        ])->assertExitCode(0);

        Storage::disk('public')->assertExists('chat/uploaded-after-the-backup.png');
        $this->assertSame('keep-me', Storage::disk('public')->get('chat/uploaded-after-the-backup.png'));
    }

    public function test_fresh_empties_the_uploads_directory_first(): void
    {
        Storage::disk('public')->put('chat/uploaded-after-the-backup.png', 'delete-me');

        $this->makeArchive('restore-test-fresh.zip', [
            'database.sql' => 'SELECT 1;',
            'files/contracts/from-archive.pdf' => 'archive-copy',
        ]);

        $this->artisan('db:restore', [
            'archive' => 'restore-test-fresh.zip',
            '--files-only' => true,
            '--fresh' => true,
            '--force' => true,
            '--skip-snapshot' => true,
        ])->assertExitCode(0);

        Storage::disk('public')->assertMissing('chat/uploaded-after-the-backup.png');
        Storage::disk('public')->assertExists('contracts/from-archive.pdf');
    }

    public function test_it_reports_files_no_row_references_without_deleting_them(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $client = Client::factory()->create([
            'manager_id' => $manager->id,
            'avatar_url' => '/storage/avatars/referenced.png',
        ]);
        Workspace::factory()->create(['client_id' => $client->id, 'manager_id' => $manager->id]);

        Storage::disk('public')->put('avatars/referenced.png', 'referenced');

        $this->makeArchive('restore-test-orphans.zip', [
            'database.sql' => 'SELECT 1;',
            'files/chat/nobody-points-here.png' => 'orphan',
        ]);

        $this->artisan('db:restore', [
            'archive' => 'restore-test-orphans.zip',
            '--files-only' => true,
            '--force' => true,
            '--skip-snapshot' => true,
        ])
            ->expectsOutputToContain('chat/nobody-points-here.png')
            ->expectsOutputToContain('Nothing was deleted')
            ->assertExitCode(0);

        // The whole point of reporting rather than pruning.
        Storage::disk('public')->assertExists('chat/nobody-points-here.png');
    }

    public function test_it_says_so_when_every_file_is_referenced(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $client = Client::factory()->create([
            'manager_id' => $manager->id,
            'avatar_url' => '/storage/avatars/referenced.png',
        ]);
        Workspace::factory()->create(['client_id' => $client->id, 'manager_id' => $manager->id]);

        $this->makeArchive('restore-test-clean.zip', [
            'database.sql' => 'SELECT 1;',
            'files/avatars/referenced.png' => 'referenced',
        ]);

        $this->artisan('db:restore', [
            'archive' => 'restore-test-clean.zip',
            '--files-only' => true,
            '--force' => true,
            '--skip-snapshot' => true,
        ])
            ->expectsOutputToContain('Every file on disk is referenced')
            ->assertExitCode(0);
    }

    public function test_it_picks_the_newest_archive_when_none_is_named(): void
    {
        $older = $this->makeArchive('restore-test-older.zip', [
            'database.sql' => 'SELECT 1;',
            'files/contracts/old.pdf' => 'old',
        ]);
        $newer = $this->makeArchive('restore-test-newer.zip', [
            'database.sql' => 'SELECT 1;',
            'files/contracts/new.pdf' => 'new',
        ]);

        // Future mtimes on purpose: storage/app/backups is the developer's
        // real backup directory and may already hold archives from an actual
        // db:backup run. Dating these ahead guarantees the command picks one
        // of ours without the test touching, moving or deleting anything the
        // developer put there.
        touch($older, time() + 1800);
        touch($newer, time() + 3600);

        $this->artisan('db:restore', ['--files-only' => true, '--force' => true, '--skip-snapshot' => true])
            ->assertExitCode(0);

        Storage::disk('public')->assertExists('contracts/new.pdf');
        Storage::disk('public')->assertMissing('contracts/old.pdf');
    }
}
