<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Mirrors app/Console/Commands/RestoreDatabase.php from shadapp-backend
 * (Postgres) verbatim — the command picks its client tool from the connection
 * driver, so the same file serves both deployments without a variant.
 *
 * Restore a db:backup archive — the database and the uploaded files together.
 *
 * This is the other half of App\Console\Commands\BackupDatabase, and the half
 * that decides whether any of it was worth doing. A backup nobody has ever
 * restored is a guess, not a recovery plan: the archive may be truncated, the
 * dump may reference a collation the new server lacks, the file half may be
 * missing — none of which is visible until someone tries. So this command
 * exists to be *run*, deliberately, on a throwaway database, before it is
 * ever needed for real.
 *
 * It restores db:backup archives only. The scoped archives produced by
 * App\Services\DataExportService (exports/client/..., exports/manager-backup/...)
 * are JSON trees for handing data to a person, not dumps — loading one back
 * into a live system is a genuinely different feature with its own
 * ID-collision and authorization problems, and DATA_SAFETY_PLAN.md §5 rules
 * it out of the first version on purpose.
 *
 * Three deliberate safety choices, all discussed with the user first:
 *
 * 1. A snapshot of the current state is taken before anything is overwritten
 *    (unless --skip-snapshot), so restoring the *wrong* archive is itself
 *    recoverable. Restoring is the one operation where the mistake destroys
 *    the evidence of the mistake.
 * 2. Uploaded files are merged, never deleted. Files the archive also has are
 *    replaced by the archive's copy; files only on disk are left alone. They
 *    are, by definition, files no row in the restored database points at, so
 *    deleting them recovers nothing and risks discarding something a human
 *    still wanted. They are reported instead — see reportOrphans().
 * 3. The restore is confirmed interactively by default, showing which
 *    database and which archive, because --force plus a shell history entry
 *    is exactly how the wrong environment gets flattened.
 *
 * --connection exists so the drill in point 1's spirit can actually be run:
 *
 *   php artisan db:restore --connection=restore_target --database-only
 *
 * replays the newest archive into the throwaway database configured as
 * 'restore_target' in config/database.php, proving the backup restores
 * without going anywhere near the live one. The alternative — editing
 * DB_DATABASE, restoring, and remembering to change it back — works exactly
 * until the day someone forgets, and then the application is quietly serving
 * a test database. Everything downstream of the replay (the connection
 * purge, the orphan report's queries) follows the same --connection, so the
 * report describes the database that was actually restored.
 */
class RestoreDatabase extends Command
{
    protected $signature = 'db:restore
        {archive? : Path to the archive; defaults to the newest in storage/app/backups}
        {--connection= : Restore into this connection instead of the default (e.g. restore_target)}
        {--database-only : Restore the SQL dump alone, leaving uploaded files untouched}
        {--files-only : Restore uploaded files alone, leaving the database untouched}
        {--fresh : Empty storage/app/public first, so the file tree matches the archive exactly}
        {--skip-snapshot : Do not back up the current state before overwriting it}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Restore the database and uploaded files from a db:backup archive';

    /**
     * Every column that can hold one of our own /storage/... values, used to
     * work out which files on disk the restored database actually references.
     * Signature columns are included because they hold either a base64 data
     * URI or an uploaded image path depending on how the signature was
     * given — FileUrl::extractStoragePath() returns null for the former, so
     * scanning them costs nothing and missing them would report every
     * uploaded signature as an orphan.
     *
     * @var array<string, array<int, string>>
     */
    private const FILE_COLUMNS = [
        'users' => ['avatar_url', 'signature_data'],
        'clients' => ['avatar_url', 'signature_data'],
        'sub_users' => ['avatar_url'],
        'contracts' => ['pdf_url', 'client_signature_data', 'company_signature_data'],
        'approvals' => ['signature'],
        'approval_certificates' => ['pdf_url'],
        'chat_messages' => ['file_url'],
        'files' => ['file_url'],
    ];

    /**
     * Same idea, for columns holding a JSON array of paths rather than one.
     *
     * @var array<string, array<int, string>>
     */
    private const JSON_FILE_COLUMNS = [
        'payments' => ['proof_file_url'],
    ];

    public function handle(): int
    {
        if ($this->option('database-only') && $this->option('files-only')) {
            $this->error('--database-only and --files-only contradict each other.');

            return self::FAILURE;
        }

        $archive = $this->resolveArchive();

        if ($archive === null) {
            return self::FAILURE;
        }

        // Validated before the driver check, the confirmation prompt and the
        // snapshot: "this file is not a backup archive" is knowable up front,
        // and there is no reason to make someone confirm a destructive
        // operation, or spend minutes on a snapshot, only to then be told the
        // archive was never usable.
        if (! $this->validateArchive($archive)) {
            return self::FAILURE;
        }

        $connection = $this->option('connection') ?: config('database.default');
        $config = config("database.connections.{$connection}");

        if (! is_array($config)) {
            $this->error("No connection named [{$connection}] in config/database.php.");

            return self::FAILURE;
        }

        if (($config['database'] ?? '') === '') {
            // Hit when --connection=restore_target is used without
            // DB_RESTORE_DATABASE set. Left without a default on purpose:
            // guessing a database name for a command that drops every table
            // it finds is not a kindness.
            $this->error("Connection [{$connection}] has no database name configured.");

            return self::FAILURE;
        }

        $driver = $config['driver'] ?? null;

        if (! $this->option('files-only') && ! in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
            $this->error("Unsupported driver [{$driver}]. Only MySQL/MariaDB and PostgreSQL can be restored.");

            return self::FAILURE;
        }

        if (! $this->confirmRestore($archive, $connection, $config, $driver)) {
            $this->warn('Aborted. Nothing was changed.');

            return self::FAILURE;
        }

        if (! $this->option('skip-snapshot') && ! $this->snapshot()) {
            return self::FAILURE;
        }

        // Staged outside storage/app so a half-extracted archive can never be
        // mistaken for restored data, and so a failure partway through leaves
        // the live directories untouched rather than half-written.
        $work = storage_path('app/restore-tmp-'.now()->format('Y-m-d_His'));
        File::ensureDirectoryExists($work);

        try {
            if (! $this->extract($archive, $work)) {
                return self::FAILURE;
            }

            if (! $this->option('files-only') && ! $this->restoreDatabase($work.DIRECTORY_SEPARATOR.'database.sql', $connection, $config, $driver)) {
                return self::FAILURE;
            }

            if (! $this->option('database-only')) {
                $this->restoreFiles($work.DIRECTORY_SEPARATOR.'files');
            }
        } finally {
            File::deleteDirectory($work);
        }

        if (! $this->option('database-only')) {
            // Deliberately the connection we just restored into, not the
            // default one: with --connection=restore_target the live database
            // is untouched, and reporting its file references against the
            // restored archive's files would be comparing two unrelated things.
            $this->reportOrphans($connection);
        }

        $this->newLine();
        $this->info('Restore complete.');

        return self::SUCCESS;
    }

    /**
     * An explicit path wins; otherwise the newest archive db:backup left
     * behind. Defaulting to "the newest" is the case that matters at 3am, but
     * it is also the case where picking the wrong file is easiest, which is
     * why confirmRestore() prints the name it chose rather than just acting.
     */
    private function resolveArchive(): ?string
    {
        $given = $this->argument('archive');

        if ($given) {
            $path = File::isFile($given) ? $given : storage_path('app/backups/'.ltrim($given, '/\\'));

            if (! File::isFile($path)) {
                $this->error("No archive at [{$given}].");

                return null;
            }

            return $path;
        }

        $directory = storage_path('app/backups');

        $newest = collect(File::isDirectory($directory) ? File::files($directory) : [])
            ->filter(fn ($file) => str_ends_with($file->getFilename(), '.zip'))
            ->sortByDesc(fn ($file) => $file->getMTime())
            ->first();

        if (! $newest) {
            $this->error('No archives in storage/app/backups — pass a path explicitly.');

            return null;
        }

        return $newest->getPathname();
    }

    private function confirmRestore(string $archive, string $connection, array $config, ?string $driver): bool
    {
        $what = match (true) {
            (bool) $this->option('database-only') => 'the database',
            (bool) $this->option('files-only') => 'the uploaded files',
            default => 'the database and the uploaded files',
        };

        $this->newLine();
        $this->line('  Archive:  '.basename($archive).' ('.$this->humanSize(File::size($archive)).')');
        $this->line('  Restores: '.$what);

        if (! $this->option('files-only')) {
            // The database name is spelled out next to the connection name so
            // that a mistyped --connection is visible here rather than
            // afterwards.
            $this->line('  Into:     '.($config['database'] ?? '?').' on '.($config['host'] ?? '?')." ({$driver}, connection: {$connection})");
        }

        if ($this->option('fresh')) {
            $this->line('  Files:    storage/app/public will be EMPTIED first');
        }

        $this->newLine();

        if ($this->option('force')) {
            return true;
        }

        // Always asks, not only in production (which is what Laravel's own
        // ConfirmableTrait would do). A developer restoring over the database
        // they spent the morning seeding has lost real work too.
        return $this->confirm('This overwrites existing data. Continue?', false);
    }

    private function snapshot(): bool
    {
        $this->info('Snapshotting the current state first (--skip-snapshot to skip)...');

        // --keep=9999 so this safety copy cannot push the very archive being
        // restored out of the retention window while the restore is running.
        if (Artisan::call('db:backup', ['--keep' => 9999]) !== 0) {
            $this->error('Snapshot failed, so the restore was not started — the current state would not have been recoverable.');
            $this->line(Artisan::output());
            $this->newLine();
            $this->warn('Pass --skip-snapshot to restore anyway.');

            return false;
        }

        return true;
    }

    private function validateArchive(string $archive): bool
    {
        $zip = new \ZipArchive();

        if ($zip->open($archive) !== true) {
            $this->error("Could not open [{$archive}] — it is not a readable zip archive.");

            return false;
        }

        $hasDump = $zip->locateName('database.sql') !== false;
        $zip->close();

        if (! $this->option('files-only') && ! $hasDump) {
            $this->error('This archive has no database.sql, so it is not a db:backup archive. A scoped data export (exports/client/..., exports/manager-backup/...) holds JSON for handing to a person and cannot be restored — see this command\'s docblock.');

            return false;
        }

        return true;
    }

    private function extract(string $archive, string $work): bool
    {
        $zip = new \ZipArchive();

        if ($zip->open($archive) !== true) {
            $this->error("Could not open [{$archive}] — it is not a readable zip archive.");

            return false;
        }

        $zip->extractTo($work);
        $zip->close();

        return true;
    }

    private function restoreDatabase(string $sqlPath, string $connection, array $config, ?string $driver): bool
    {
        if (! File::exists($sqlPath) || File::size($sqlPath) === 0) {
            $this->error('The archive\'s database.sql is empty — refusing to restore from it.');

            return false;
        }

        $this->info('Restoring database ('.$this->humanSize(File::size($sqlPath)).')...');

        $result = match ($driver) {
            'mysql', 'mariadb' => $this->restoreMysql($config, $sqlPath),
            'pgsql' => $this->restorePostgres($config, $sqlPath),
            default => null,
        };

        if ($result === null || ! $result->isSuccessful()) {
            $output = $result ? trim($result->getErrorOutput() ?: $result->getOutput()) : 'no restore tool for this driver';
            $this->error('Restore failed: '.$output);

            if (str_contains($output, 'not recognized') || str_contains($output, 'not found')) {
                $this->newLine();
                $this->warn('The client tool is not on your PATH. Same setting db:backup uses:');
                $this->line('  DB_DUMP_BINARY_PATH=C:/wamp64/bin/mysql/mysql8.0.31/bin');
                $this->line('Add that to .env (adjust the version), then run: php artisan config:clear');
            }

            $this->newLine();
            $this->warn('The database may now be partially restored — the dump drops each table before recreating it, so a failure halfway leaves it incomplete. Re-run against a known-good archive before using this database.');

            return false;
        }

        // The tables this connection knew about were just dropped and
        // recreated underneath it by an outside process; purge drops the
        // cached PDO instance so the next query opens a fresh one.
        DB::purge($connection);

        return true;
    }

    private function restoreMysql(array $config, string $sqlPath): Process
    {
        $command = [
            $this->binary('mysql'),
            '--host='.$config['host'],
            '--port='.$config['port'],
            '--user='.$config['username'],
            $config['database'],
        ];

        // In batch mode mysql aborts on the first error, which is what we
        // want — a dump replayed "mostly" is worse than one that failed
        // loudly, because it looks like it worked.
        $process = $this->makeProcess($command, ['MYSQL_PWD' => (string) $config['password']]);
        $process->setInput(fopen($sqlPath, 'r'));
        $process->run();

        return $process;
    }

    private function restorePostgres(array $config, string $sqlPath): Process
    {
        $command = [
            $this->binary('psql'),
            '--host='.$config['host'],
            '--port='.$config['port'],
            '--username='.$config['username'],
            '--dbname='.$config['database'],
            // Without this psql happily continues past a failed statement and
            // still exits 0 — the restore would report success having skipped
            // whatever broke. This is the single most important flag here.
            '--set=ON_ERROR_STOP=1',
            '--file='.$sqlPath,
        ];

        $process = $this->makeProcess($command, ['PGPASSWORD' => (string) $config['password']]);
        $process->run();

        return $process;
    }

    /**
     * Merges the archive's files/ tree into storage/app/public. Existing files
     * at the same path are overwritten by the archive's copy — that is the
     * point, since the restored database references the archive's version.
     * Files only on disk are left alone; see the class docblock and
     * reportOrphans().
     */
    private function restoreFiles(string $filesDir): void
    {
        if (! File::isDirectory($filesDir)) {
            $this->warn('This archive contains no uploaded files (taken with --database-only?) — only the database was restored.');

            return;
        }

        // Everything here goes through the 'public' disk rather than
        // storage_path() so the same code path is exercised under
        // Storage::fake() in tests instead of writing to the real upload
        // directory — the pattern DataExportService already follows.
        $disk = Storage::disk('public');

        if ($this->option('fresh')) {
            foreach ($disk->directories() as $directory) {
                $disk->deleteDirectory($directory);
            }

            $disk->delete($disk->files());
            $this->line('  Emptied the uploads directory (--fresh)');
        }

        $count = 0;

        foreach (File::allFiles($filesDir) as $file) {
            // writeStream, not put(File::get(...)): a restore can carry
            // hundreds of megabytes of contracts and proofs, and reading each
            // one fully into memory first is a needless way to hit the limit.
            $handle = fopen($file->getPathname(), 'r');
            $disk->writeStream(str_replace('\\', '/', $file->getRelativePathname()), $handle);
            fclose($handle);
            $count++;
        }

        $this->info("Restored {$count} uploaded file(s).");
    }

    /**
     * Lists files sitting in storage/app/public that no row in the restored
     * database points at — almost always things uploaded after the archive
     * was taken, now unreachable because the row that referenced them is gone.
     *
     * Reported rather than deleted, deliberately (see the class docblock):
     * the command has no way to tell "uploaded after the backup" from "the
     * backup missed it", and only a human knows which of those matters. The
     * list is printed so that judgement can actually be made.
     */
    private function reportOrphans(string $connection): void
    {
        try {
            $referenced = $this->referencedPaths($connection);
        } catch (\Throwable $e) {
            // The restore itself succeeded; failing to produce an advisory
            // report is not a reason to report the whole operation as failed.
            $this->warn('Could not check for orphaned files: '.$e->getMessage());

            return;
        }

        // allFiles() returns paths relative to the disk root with forward
        // slashes — the same shape FileUrl::extractStoragePath() produces, so
        // the two sets compare directly with no normalising on either side.
        $orphans = collect(Storage::disk('public')->allFiles())
            ->reject(fn (string $path) => isset($referenced[$path]))
            ->values();

        $this->newLine();

        if ($orphans->isEmpty()) {
            $this->info('Every file on disk is referenced by the restored database.');

            return;
        }

        $this->warn($orphans->count().' file(s) on disk are not referenced by any row in the restored database:');

        foreach ($orphans->take(20) as $path) {
            $this->line('  '.$path);
        }

        if ($orphans->count() > 20) {
            $this->line('  ... and '.($orphans->count() - 20).' more');
        }

        $this->newLine();
        $this->line('Nothing was deleted. These are most likely uploads made after the archive was taken; review them before removing anything.');
    }

    /**
     * @return array<string, true> referenced storage paths, as a set
     */
    private function referencedPaths(string $connection): array
    {
        $paths = [];

        $collect = function (?string $value) use (&$paths): void {
            if (! $value) {
                return;
            }

            $path = \App\Support\FileUrl::extractStoragePath($value);

            if ($path !== null) {
                $paths[$path] = true;
            }
        };

        // Schema is checked per table/column rather than assumed: this same
        // command ships in both the Postgres and MySQL backends, and a column
        // dropped by a later migration should degrade the report, not throw.
        foreach (self::FILE_COLUMNS as $table => $columns) {
            foreach ($this->existingColumns($connection, $table, $columns) as $column) {
                foreach (DB::connection($connection)->table($table)->whereNotNull($column)->pluck($column) as $value) {
                    $collect(is_string($value) ? $value : null);
                }
            }
        }

        foreach (self::JSON_FILE_COLUMNS as $table => $columns) {
            foreach ($this->existingColumns($connection, $table, $columns) as $column) {
                foreach (DB::connection($connection)->table($table)->whereNotNull($column)->pluck($column) as $value) {
                    $decoded = is_string($value) ? json_decode($value, true) : null;

                    foreach (is_array($decoded) ? $decoded : [] as $entry) {
                        $collect(is_string($entry) ? $entry : null);
                    }
                }
            }
        }

        return $paths;
    }

    /**
     * @param  array<int, string>  $columns
     * @return array<int, string>
     */
    private function existingColumns(string $connection, string $table, array $columns): array
    {
        if (! Schema::connection($connection)->hasTable($table)) {
            return [];
        }

        return array_values(array_filter(
            $columns,
            fn (string $column) => Schema::connection($connection)->hasColumn($table, $column)
        ));
    }

    /**
     * Resolves a client tool, honouring the same DB_DUMP_BINARY_PATH that
     * db:backup uses — mysql and psql live next to mysqldump and pg_dump, so
     * one setting covers both directions.
     */
    private function binary(string $name): string
    {
        $path = rtrim((string) config('database.dump_binary_path'), '\\/');

        return $path === '' ? $name : $path.DIRECTORY_SEPARATOR.$name;
    }

    private function makeProcess(array $command, array $env): Process
    {
        $process = new Process($command, base_path(), $env);
        // Replaying a large dump is slower than producing it; the default 60s
        // would abort a restore that was working fine.
        $process->setTimeout(1800);

        return $process;
    }

    private function humanSize(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024) {
                return round($bytes, 1)." {$unit}";
            }
            $bytes /= 1024;
        }

        return round($bytes, 1).' TB';
    }
}
