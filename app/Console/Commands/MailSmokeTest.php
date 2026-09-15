<?php

namespace App\Console\Commands;

use App\Events\ClientCreated;
use App\Events\ContractClientApproved;
use App\Events\ContractCompanyApproved;
use App\Events\ContractSent;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Diagnostic-only: fires the real mail events against an existing contract so
 * the actual listener/recipient logic runs, without driving the dashboard UI.
 *
 * This exists because "did the email go out?" has several independent failure
 * points that all fail silently: MAIL_MAILER still on `log`, no queue worker
 * consuming the queued mailables, or no super admin with an `official_email`
 * set (in which case the company copy simply has nowhere to go). The command
 * prints each of those before sending anything.
 *
 * Nothing is written to the database — it reuses an existing contract and only
 * dispatches events.
 */
class MailSmokeTest extends Command
{
    protected $signature = 'mail:smoke-test
        {--contract= : Contract id to use (defaults to the most recent one)}
        {--welcome : Fire ClientCreated (client credentials email)}
        {--sent : Fire ContractSent}
        {--client-approved : Fire ContractClientApproved (generates the client-signature PDF)}
        {--company-approved : Fire ContractCompanyApproved (generates the both-signatures PDF)}
        {--all : Fire every event above, in order}
        {--failures : Print the reason behind recent failed queue jobs and exit}
        {--force : Allow running against a mail host that is not Mailtrap/log}';

    protected $description = 'Fire the real contract/client mail events at a test inbox and report who receives what';

    public function handle(): int
    {
        if ($this->option('failures')) {
            return $this->showFailures();
        }

        $mailer = config('mail.default');
        $host = config("mail.mailers.{$mailer}.host");
        $queue = config('queue.default');

        $this->line('');
        $this->info('— Mail configuration —');
        $this->line("  mailer:      {$mailer}");
        $this->line('  host:        ' . ($host ?: '(n/a)'));
        $this->line('  from:        ' . config('mail.from.address'));
        $this->line("  queue:       {$queue}");
        $this->line('');

        // Guard: every mailable here goes to whatever address the listeners
        // compute — real client and admin addresses out of the database. That
        // is fine against a Mailtrap sandbox or the log driver, which swallow
        // everything, but running it against production SMTP would send real
        // people fake contract notifications.
        $isSafe = $mailer === 'log' || ($host && str_contains($host, 'mailtrap'));
        if (! $isSafe && ! $this->option('force')) {
            $this->error('Refusing to run: the configured mailer is neither `log` nor Mailtrap.');
            $this->line('These events email real client and admin addresses from the database.');
            $this->line('Re-run with --force only if you are certain that is what you want.');

            return self::FAILURE;
        }

        if ($queue !== 'sync') {
            $this->warn("Queue is `{$queue}`, not `sync` — every mailable except the client");
            $this->warn('welcome email is queued. Nothing will actually be delivered unless');
            $this->warn('`php artisan queue:work` is running in another terminal.');
            $this->line('');
        }

        $officialEmails = User::where('role', User::ROLE_SUPER_ADMIN)
            ->whereNotNull('official_email')
            ->pluck('official_email')
            ->all();

        $this->info('— Official company addresses (super admins with official_email) —');
        if (empty($officialEmails)) {
            $this->warn('  none set — the "company" copy of every contract email has no recipient.');
            $this->warn('  Set it from the dashboard (account settings) before reading anything');
            $this->warn('  into a missing company email.');
        } else {
            foreach ($officialEmails as $email) {
                $this->line("  {$email}");
            }
        }
        $this->line('');

        $contract = $this->option('contract')
            ? Contract::with('workspace.client', 'creator')->find($this->option('contract'))
            : Contract::with('workspace.client', 'creator')->latest('id')->first();

        if (! $contract) {
            $this->error('No contract found. Create one first, or pass --contract=<id>.');

            return self::FAILURE;
        }

        $client = $contract->workspace?->client;
        $manager = $contract->creator;

        if (! $client) {
            $this->error("Contract #{$contract->id} has no workspace/client attached.");

            return self::FAILURE;
        }

        $this->info('— Test subject —');
        $this->line("  contract:    #{$contract->id} \"{$contract->title}\" (status: {$contract->status})");
        $this->line('  client:      ' . ($client->company_name ?: '—') . ' <' . ($client->email ?: 'no email') . '>');
        $this->line('  manager:     ' . ($manager?->name ?: '—') . ' <' . ($manager?->email ?: 'no email') . '>');
        $this->line('');

        $adminEmails = User::where('role', User::ROLE_SUPER_ADMIN)->pluck('email')->all();

        $this->info('— Who each event mails (after de-duplication) —');
        $this->listRecipients('ContractSent', array_merge([$client->email, $manager?->email], $officialEmails));
        $this->listRecipients('ContractClientApproved', array_merge([$manager?->email], $adminEmails, $officialEmails));
        $this->listRecipients('ContractCompanyApproved', array_merge([$client->email], $officialEmails));
        $this->listRecipients('ClientCreated (welcome)', [$client->email]);
        $this->line('');

        $all = $this->option('all');
        $fired = 0;

        if ($all || $this->option('welcome')) {
            // A throwaway string, never a real credential: this only exercises
            // the template and the synchronous send path.
            event(new ClientCreated($client, 'SMOKE-TEST-NOT-A-REAL-PASSWORD'));
            $this->line('  fired: ClientCreated (sent synchronously, no queue worker needed)');
            $fired++;
        }

        if ($all || $this->option('sent')) {
            event(new ContractSent($contract));
            $this->line('  fired: ContractSent (queued)');
            $fired++;
        }

        if ($all || $this->option('client-approved')) {
            event(new ContractClientApproved($contract));
            $this->line('  fired: ContractClientApproved (queued, generates a PDF first)');
            $fired++;
        }

        if ($all || $this->option('company-approved')) {
            event(new ContractCompanyApproved($contract));
            $this->line('  fired: ContractCompanyApproved (queued, generates a PDF first)');
            $fired++;
        }

        $this->line('');

        if ($fired === 0) {
            $this->warn('Nothing fired — this was a dry run.');
            $this->line('Add --welcome, --sent, --client-approved, --company-approved, or --all.');

            return self::SUCCESS;
        }

        $this->info("Dispatched {$fired} event(s).");
        $this->line('Queued mail lands in the inbox only once a worker picks it up.');
        $this->line('If something never arrives, check the `failed_jobs` table for the reason.');

        return self::SUCCESS;
    }

    /**
     * The worker prints only RUNNING/DONE/FAIL — the actual exception lands in
     * `failed_jobs`, which is where the useful part is (SMTP rejection text,
     * missing attachment path, and so on).
     */
    private function showFailures(): int
    {
        $rows = DB::table('failed_jobs')->latest('id')->take(10)->get();

        if ($rows->isEmpty()) {
            $this->info('No failed jobs recorded.');

            return self::SUCCESS;
        }

        $this->line('');
        $this->info('— Recent failed jobs (newest first) —');

        foreach ($rows as $row) {
            $payload = json_decode($row->payload, true);
            $name = $payload['displayName'] ?? 'unknown job';

            // The first line of the exception is the class + message; the rest
            // is a stack trace that isn't useful here.
            $firstLine = strtok((string) $row->exception, "\n");

            $this->line('');
            $this->line("  #{$row->id}  {$row->failed_at}");
            $this->line("  job:    {$name}");
            $this->line('  reason: ' . $firstLine);
        }

        $this->line('');
        $this->line('Clear them once the cause is fixed: php artisan queue:flush');

        return self::SUCCESS;
    }

    /**
     * @param array<int, string|null> $addresses
     */
    private function listRecipients(string $label, array $addresses): void
    {
        $unique = [];
        foreach ($addresses as $address) {
            $address = trim((string) $address);
            if ($address === '') {
                continue;
            }
            $unique[mb_strtolower($address)] = $address;
        }

        $this->line("  {$label}:");
        if (empty($unique)) {
            $this->line('    (no recipients — this email would go nowhere)');

            return;
        }
        foreach ($unique as $address) {
            $this->line("    {$address}");
        }
    }
}
