<?php

namespace App\Console\Commands;

use App\Models\LoginAttempt;
use Illuminate\Console\Command;

/**
 * Failed login attempts are append-only and unbounded: every typo and every
 * bot that finds the endpoint adds a row, forever. Without this the table
 * is the one part of the schema that grows with hostile traffic rather than
 * with the business, and nothing else prunes it.
 *
 * 90 days is chosen to be longer than any support question realistically
 * reaches back ("why couldn't I log in last week") while still being short
 * enough that the table stays small. Raise it with --days if an
 * investigation needs more history; the data is cheap, it just shouldn't
 * accumulate silently.
 */
class PruneLoginAttempts extends Command
{
    protected $signature = 'login-attempts:prune {--days=90 : Delete attempts older than this many days}';

    protected $description = 'Delete old rows from login_attempts';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        // Chunked rather than a single DELETE: after a sustained attack this
        // could be millions of rows, and one unbounded delete would hold
        // locks long enough to be felt by live logins hitting the same
        // table.
        $deleted = 0;
        do {
            $batch = LoginAttempt::where('created_at', '<', $cutoff)->limit(1000)->delete();
            $deleted += $batch;
        } while ($batch > 0);

        $this->info("Deleted {$deleted} login attempt(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
