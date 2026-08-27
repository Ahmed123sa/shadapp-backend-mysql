<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Back up the database *and* the uploaded files, into one archive.
 *
 * The two halves are useless apart. The database stores file *paths*, not the
 * files: signed contract PDFs, signature images, payment proofs and chat
 * attachments all live on disk under storage/app/public. Restoring a
 * database-only backup gives you every record intact and every document link
 * broken — which for signed contracts and payment evidence is not a recovery
 * at all. Keeping them in a single timestamped archive also guarantees the
 * two halves are from the same moment.
 *
 * The dump itself is built on the engine's own tool rather than a package or
 * a hand-rolled "SELECT everything" loop. mysqldump and pg_dump produce a
 * file the corresponding restore tool accepts unconditionally, including the
 * parts a naive dump forgets — foreign key ordering, character sets,
 * sequences and triggers. A backup that cannot be restored cleanly is not a
 * backup.
 *
 * Which tool runs is chosen from the connection driver, so this works on both
 * the Postgres and MySQL deployments without configuration.
 */
class BackupDatabase extends Command
{
    protected $signature = 'db:backup
        {--keep=14 : How many backup archives to retain}
        {--database-only : Skip uploaded files and archive the SQL dump alone}';

    protected $description = 'Back up the database and uploaded files to storage/app/backups';

    public function handle(): int
    {
        $connection = config('database.default');
        $config = config("database.connections.{$connection}");
        $driver = $config['driver'] ?? null;

        $directory = storage_path('app/backups');
        File::ensureDirectoryExists($directory);

        $stamp = now()->format('Y-m-d_His');
        $name = ($config['database'] ?? 'database').'-'.$stamp;

        // Staged outside the backups directory so a half-written run is never
        // mistaken for a finished archive by prune() or by a human looking at
        // the folder.
        $work = storage_path('app/backup-tmp-'.$stamp);
        File::ensureDirectoryExists($work);

        try {
            $sqlPath = $work.DIRECTORY_SEPARATOR.'database.sql';

            $this->info("Dumping [{$connection}] ({$driver})...");

            $result = match ($driver) {
                'mysql', 'mariadb' => $this->dumpMysql($config, $sqlPath),
                'pgsql' => $this->dumpPostgres($config, $sqlPath),
                default => null,
            };

            if ($result === null) {
                $this->error("Unsupported driver [{$driver}]. Only MySQL/MariaDB and PostgreSQL can be dumped.");
                return self::FAILURE;
            }

            if (!$result->isSuccessful()) {
                $output = trim($result->getErrorOutput() ?: $result->getOutput());
                $this->error('Dump failed: '.$output);

                // "not recognized" / "not found" means the binary is missing
                // from PATH rather than anything being wrong with the database,
                // and the raw message gives no hint about the fix.
                if (str_contains($output, 'not recognized') || str_contains($output, 'not found')) {
                    $this->newLine();
                    $this->warn('The dump tool is not on your PATH. Point at it directly instead:');
                    // Forward slashes, unquoted: a double-quoted .env value is
                    // parsed for escape sequences and a Windows path fails on
                    // \w, \b and friends before it is ever used.
                    $this->line('  DB_DUMP_BINARY_PATH=C:/wamp64/bin/mysql/mysql8.0.31/bin');
                    $this->line('Add that to .env (adjust the version), then run: php artisan config:clear');
                }

                return self::FAILURE;
            }

            if (!File::exists($sqlPath) || File::size($sqlPath) === 0) {
                // A truncated or empty dump is worse than none: it sits in the
                // folder looking like a valid backup and only reveals itself
                // as unusable at the moment you need it.
                $this->error('Dump produced an empty file — treating as a failure.');
                return self::FAILURE;
            }

            $this->info('Database dump: '.$this->humanSize(File::size($sqlPath)));

            $archivePath = $directory.DIRECTORY_SEPARATOR.$name.'.zip';

            if (!$this->archive($work, $archivePath)) {
                return self::FAILURE;
            }

            $this->info('Archive: '.$name.'.zip ('.$this->humanSize(File::size($archivePath)).')');
        } finally {
            // Runs even on early return, so a failed dump never leaves a stray
            // copy of production data sitting in storage/app.
            File::deleteDirectory($work);
        }

        $this->prune($directory, (int) $this->option('keep'));

        return self::SUCCESS;
    }

    /**
     * Zip the staged dump together with the uploaded files.
     *
     * Uses PHP's ZipArchive rather than shelling out to `zip`/`tar`, which are
     * not installed by default on Windows — this has to work on the WAMP
     * development machine as well as the Linux server.
     */
    private function archive(string $work, string $archivePath): bool
    {
        $zip = new \ZipArchive();

        if ($zip->open($archivePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            $this->error("Could not create archive at {$archivePath}");
            return false;
        }

        $zip->addFile($work.DIRECTORY_SEPARATOR.'database.sql', 'database.sql');

        if (!$this->option('database-only')) {
            $uploads = storage_path('app/public');

            if (File::isDirectory($uploads)) {
                $count = 0;

                foreach (File::allFiles($uploads) as $file) {
                    // Stored under files/ so a restore can tell at a glance
                    // which half of the archive is which.
                    $zip->addFile(
                        $file->getPathname(),
                        'files/'.str_replace('\\', '/', $file->getRelativePathname())
                    );
                    $count++;
                }

                $this->info("Uploaded files: {$count}");
            } else {
                $this->warn('No storage/app/public directory — archiving the database only.');
            }
        }

        $zip->close();

        return true;
    }

    private function dumpMysql(array $config, string $path): Process
    {
        $command = [
            $this->binary('mysqldump'),
            '--host='.$config['host'],
            '--port='.$config['port'],
            '--user='.$config['username'],
            // Emits DROP TABLE IF EXISTS before each CREATE, so restoring over
            // an existing database replaces it cleanly instead of failing on
            // the first table that already exists and leaving it half-restored.
            '--add-drop-table',
            // Dumps inside a transaction: a consistent snapshot without locking
            // the tables, so the app keeps serving traffic during the backup.
            '--single-transaction',
            '--routines',
            '--triggers',
            '--result-file='.$path,
            $config['database'],
        ];

        // Passing the password on the command line would expose it to anyone
        // running `ps`. MYSQL_PWD is read by the client from the environment
        // of this process only.
        return $this->runProcess($command, ['MYSQL_PWD' => (string) $config['password']]);
    }

    private function dumpPostgres(array $config, string $path): Process
    {
        $command = [
            $this->binary('pg_dump'),
            '--host='.$config['host'],
            '--port='.$config['port'],
            '--username='.$config['username'],
            '--clean',
            '--if-exists',
            '--no-owner',
            '--no-privileges',
            '--file='.$path,
            $config['database'],
        ];

        // Same reasoning as MYSQL_PWD above.
        return $this->runProcess($command, ['PGPASSWORD' => (string) $config['password']]);
    }

    /**
     * Resolve a dump tool, honouring DB_DUMP_BINARY_PATH.
     *
     * On a Linux server these are on the PATH and the name alone is enough.
     * WAMP and XAMPP install them under the bundled MySQL directory without
     * touching the PATH, so the location has to be configurable rather than
     * assumed.
     */
    private function binary(string $name): string
    {
        $path = rtrim((string) config('database.dump_binary_path'), '\\/');

        return $path === '' ? $name : $path.DIRECTORY_SEPARATOR.$name;
    }

    /**
     * Named runProcess, not run: Command::run() already exists as a public
     * method on the Laravel base class, and PHP refuses to let a subclass
     * narrow its visibility.
     */
    private function runProcess(array $command, array $env): Process
    {
        $process = new Process($command, base_path(), $env);
        // Large databases legitimately take a while; the default 60s timeout
        // would abort them and report a failure that isn't one.
        $process->setTimeout(600);
        $process->run();

        return $process;
    }

    /**
     * Keep the newest $keep archives and delete the rest.
     *
     * Without this the directory grows until the disk fills, at which point the
     * application starts failing for reasons that look nothing like "backups".
     */
    private function prune(string $directory, int $keep): void
    {
        if ($keep < 1) {
            return;
        }

        $files = collect(File::files($directory))
            ->filter(fn ($file) => str_ends_with($file->getFilename(), '.zip'))
            ->sortByDesc(fn ($file) => $file->getMTime())
            ->values();

        $stale = $files->slice($keep);

        foreach ($stale as $file) {
            File::delete($file->getPathname());
        }

        if ($stale->isNotEmpty()) {
            $this->info("Pruned {$stale->count()} old backup(s), keeping {$keep}.");
        }
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
