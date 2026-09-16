<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Contract;
use App\Models\DataExport;
use App\Models\Meeting;
use App\Models\Payment;
use App\Models\SubUser;
use App\Models\User;
use App\Support\FileUrl;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Mirrors app/Services/DataExportService.php from shadapp-backend (Postgres)
 * verbatim — the only external command it shells out to (db:backup) is
 * already driver-aware, so nothing here needs a MySQL-specific variant.
 *
 * Builds the zip archive for a DataExport request. DATA_SAFETY_PLAN.md §3.3
 * documents the exact tree per scope; §3.4 requires an explicit whitelist of
 * columns rather than dumping models wholesale, since a raw dump of e.g.
 * User or Client would carry `password` straight through if a column were
 * ever added carelessly. In practice `password` is already `$hidden` on
 * every model that has one (User's #[Hidden] attribute, Client/SubUser's
 * $hidden property), so array/JSON casting already strips it — the explicit
 * field lists below are the belt to that suspenders: a column added to one
 * of these tables later does not silently start appearing in an export
 * just because it exists on the model.
 *
 * personal_access_tokens, password reset tokens and mobile_notification_tokens
 * are excluded by construction rather than by filtering: nothing here ever
 * touches those tables in the first place, since the tree walked below is
 * built entirely from the explicit relations DATA_SAFETY_PLAN.md §3.3 lists.
 *
 * Every archive is staged in a throwaway directory and zipped at the end —
 * the same shape as App\Console\Commands\BackupDatabase — so a half-built
 * export is never mistaken for a finished one, and cleanup runs even if a
 * step in the middle throws.
 */
class DataExportService
{
    public function build(DataExport $dataExport): string
    {
        return match ($dataExport->scope) {
            DataExport::SCOPE_SYSTEM => $this->buildSystemArchive(),
            DataExport::SCOPE_MANAGER => $this->buildManagerArchive(User::findOrFail($dataExport->scope_id)),
            DataExport::SCOPE_CLIENT => $this->buildClientArchive(Client::findOrFail($dataExport->scope_id)),
            default => throw new \InvalidArgumentException("Unknown export scope [{$dataExport->scope}]."),
        };
    }

    /**
     * scope=system is, deliberately, the same archive db:backup already
     * produces (DATA_SAFETY_PLAN.md §3.3: "عمليًا هو نفس شغل db:backup").
     * Reused rather than duplicated so the two never drift apart — db:backup
     * already handles the driver-specific dump command, the
     * single-transaction snapshot, and zipping the uploaded files alongside
     * it. The resulting archive is copied (not moved) into the exports/
     * directory so it goes through the 7-day expiry/prune cycle that governs
     * exports, independent of db:backup's own --keep retention.
     */
    private function buildSystemArchive(): string
    {
        $backupsDir = storage_path('app/backups');
        $before = collect(File::exists($backupsDir) ? File::files($backupsDir) : [])
            ->map(fn ($f) => $f->getPathname())
            ->all();

        $exitCode = Artisan::call('db:backup', ['--keep' => 9999]);

        if ($exitCode !== 0) {
            throw new \RuntimeException('db:backup failed while building a system-scope export: '.Artisan::output());
        }

        $after = collect(File::files($backupsDir))->sortByDesc(fn ($f) => $f->getMTime())->values();
        $newest = $after->first(fn ($f) => ! in_array($f->getPathname(), $before, true)) ?? $after->first();

        if (! $newest) {
            throw new \RuntimeException('db:backup reported success but produced no archive.');
        }

        $relative = 'exports/'.pathinfo($newest->getFilename(), PATHINFO_FILENAME).'.zip';
        Storage::disk('local')->makeDirectory('exports');
        File::copy($newest->getPathname(), Storage::disk('local')->path($relative));

        return $relative;
    }

    private function buildManagerArchive(User $manager): string
    {
        $stamp = now()->format('Y-m-d_His');
        $work = storage_path('app/export-tmp-manager-'.$manager->id.'-'.$stamp);
        File::ensureDirectoryExists($work);

        try {
            File::put($work.'/manager.json', $this->encode($this->managerFields($manager)));

            foreach ($manager->managedClients as $client) {
                $this->writeClientTree($client, $work.'/clients/client-'.$client->id);
            }

            $relative = 'exports/manager-'.$manager->id.'-'.$stamp.'.zip';
            $this->zip($work, $relative);

            return $relative;
        } finally {
            File::deleteDirectory($work);
        }
    }

    private function buildClientArchive(Client $client): string
    {
        $stamp = now()->format('Y-m-d_His');
        $work = storage_path('app/export-tmp-client-'.$client->id.'-'.$stamp);

        try {
            $this->writeClientTree($client, $work);

            $relative = 'exports/client-'.$client->id.'-'.$stamp.'.zip';
            $this->zip($work, $relative);

            return $relative;
        } finally {
            File::deleteDirectory($work);
        }
    }

    /**
     * DATA_SAFETY_PLAN.md §3.3's "single client" tree, written as JSON files
     * under $dir plus the real uploaded files under $dir/files/ — used both
     * for a standalone client export and once per client inside a manager
     * export.
     */
    private function writeClientTree(Client $client, string $dir): void
    {
        File::ensureDirectoryExists($dir);
        $filesDir = $dir.'/files';

        File::put($dir.'/client.json', $this->encode($this->clientFields($client)));
        File::put($dir.'/sub_users.json', $this->encode($client->subUsers->map(fn ($s) => $this->subUserFields($s))->all()));
        File::put($dir.'/audit_logs.json', $this->encode($this->auditLogFields($client)));

        $this->copyStoredFile($client->getRawOriginal('avatar_url'), $filesDir);

        $workspace = $client->workspace;
        if (! $workspace) {
            return;
        }

        File::put($dir.'/workspace.json', $this->encode([
            'id' => $workspace->id,
            'status' => $workspace->status,
            'activated_at' => $workspace->activated_at?->toIso8601String(),
        ]));

        $contracts = $workspace->contracts()->with(['clauses', 'requiredDocuments'])->get();
        File::put($dir.'/contracts.json', $this->encode($contracts->map(function (Contract $contract) use ($filesDir) {
            $this->copyStoredFile($contract->getRawOriginal('pdf_url'), $filesDir);

            return $this->contractFields($contract);
        })->all()));

        $payments = $workspace->payments;
        File::put($dir.'/payments.json', $this->encode($payments->map(function (Payment $payment) use ($filesDir) {
            foreach ($this->decodeUrls($payment->getRawOriginal('proof_file_url')) as $raw) {
                $this->copyStoredFile($raw, $filesDir);
            }

            return $this->paymentFields($payment);
        })->all()));

        $approvals = $workspace->approvals()->with('certificate')->get();
        File::put($dir.'/approvals.json', $this->encode($approvals->map(function ($approval) use ($filesDir) {
            if ($approval->certificate) {
                $this->copyStoredFile($approval->certificate->getRawOriginal('pdf_url'), $filesDir);
            }

            return $this->approvalFields($approval);
        })->all()));

        $meetings = $workspace->meetings;
        File::put($dir.'/meetings.json', $this->encode($meetings->map(fn (Meeting $m) => $this->meetingFields($m))->all()));

        $messages = $workspace->chatMessages;
        File::put($dir.'/chat_messages.json', $this->encode($messages->map(function ($message) use ($filesDir) {
            $this->copyStoredFile($message->getRawOriginal('file_url'), $filesDir);

            return $this->chatMessageFields($message);
        })->all()));

        $files = $workspace->files;
        File::put($dir.'/files.json', $this->encode($files->map(function ($file) use ($filesDir) {
            $this->copyStoredFile($file->getRawOriginal('file_url'), $filesDir);

            return $this->fileFields($file);
        })->all()));

        $docDefs = $workspace->documentDefinitions;
        File::put($dir.'/document_definitions.json', $this->encode($docDefs->map(fn ($d) => $this->documentDefinitionFields($d))->all()));
    }

    // -- Whitelisted field maps (§3.4) ---------------------------------

    private function managerFields(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'official_email' => $user->official_email,
            'role' => $user->role,
            'is_active' => $user->is_active,
            'deactivated_at' => $user->deactivated_at?->toIso8601String(),
            'date_of_birth' => $user->date_of_birth?->toDateString(),
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }

    private function clientFields(Client $client): array
    {
        return [
            'id' => $client->id,
            'uuid' => $client->uuid,
            'company_name' => $client->company_name,
            'contact_person' => $client->contact_person,
            'email' => $client->email,
            'phone' => $client->phone,
            'manager_id' => $client->manager_id,
            'status' => $client->status,
            'client_type' => $client->client_type,
            'notes' => $client->notes,
            'country' => $client->country,
            'industry' => $client->industry,
            'contract_value' => $client->contract_value,
            'payment_status' => $client->payment_status,
            'date_of_birth' => $client->date_of_birth?->toDateString(),
            'address' => $client->address,
            'maps_url' => $client->maps_url,
            'location_address' => $client->location_address,
            'created_at' => $client->created_at?->toIso8601String(),
        ];
    }

    private function subUserFields(SubUser $subUser): array
    {
        return [
            'id' => $subUser->id,
            'name' => $subUser->name,
            'email' => $subUser->email,
            'phone' => $subUser->phone,
            'date_of_birth' => $subUser->date_of_birth?->toDateString(),
            'permissions' => $subUser->permissions,
            'created_at' => $subUser->created_at?->toIso8601String(),
        ];
    }

    private function contractFields(Contract $contract): array
    {
        return [
            'id' => $contract->id,
            'title' => $contract->title,
            'status' => $contract->status,
            'contract_type' => $contract->contract_type,
            'value' => $contract->value,
            'currency' => $contract->currency,
            'start_date' => $contract->start_date,
            'end_date' => $contract->end_date,
            'client_signed_at' => $contract->client_signed_at?->toIso8601String(),
            'company_signed_at' => $contract->company_signed_at?->toIso8601String(),
            'archived_at' => $contract->archived_at?->toIso8601String(),
            'created_by' => $contract->created_by,
            'clauses' => $contract->clauses->map(fn ($c) => [
                'content' => $c->content,
                'type' => $c->type,
                'sort_order' => $c->sort_order,
            ])->all(),
            'required_documents' => $contract->requiredDocuments->map(fn ($d) => [
                'name' => $d->name,
                'description' => $d->description,
                'is_required' => $d->is_required,
            ])->all(),
        ];
    }

    private function paymentFields(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'method_type' => $payment->method_type,
            'status' => $payment->status,
            'due_date' => $payment->due_date?->toDateString(),
            'notes' => $payment->notes,
            'reviewed_by' => $payment->reviewed_by,
            'reviewed_at' => $payment->reviewed_at?->toIso8601String(),
            'created_at' => $payment->created_at?->toIso8601String(),
        ];
    }

    private function approvalFields($approval): array
    {
        return [
            'id' => $approval->id,
            'title' => $approval->title,
            'description' => $approval->description,
            'status' => $approval->status,
            'reference_no' => $approval->reference_no,
            'responded_at' => $approval->responded_at?->toIso8601String(),
            'reason' => $approval->reason,
            'requested_by' => $approval->requested_by,
            'created_at' => $approval->created_at?->toIso8601String(),
            'certificate_generated_at' => $approval->certificate?->generated_at?->toIso8601String(),
        ];
    }

    private function meetingFields(Meeting $meeting): array
    {
        return [
            'id' => $meeting->id,
            'title' => $meeting->title,
            'scheduled_at' => $meeting->scheduled_at?->toIso8601String(),
            'duration_minutes' => $meeting->duration_minutes,
            'status' => $meeting->status,
            'notes' => $meeting->notes,
            'created_by' => $meeting->created_by,
            'ended_at' => $meeting->ended_at?->toIso8601String(),
        ];
    }

    private function chatMessageFields($message): array
    {
        return [
            'id' => $message->id,
            'sender_type' => $message->sender_type,
            'sender_id' => $message->sender_id,
            'message' => $message->message,
            'type' => $message->type,
            'requires_action' => $message->requires_action,
            'action_taken' => $message->action_taken,
            'action_result' => $message->action_result,
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }

    private function fileFields($file): array
    {
        return [
            'id' => $file->id,
            'name' => $file->name,
            'type' => $file->type,
            'size' => $file->size,
            'status' => $file->status,
            'uploaded_by_type' => $file->uploaded_by_type,
            'uploaded_by_id' => $file->uploaded_by_id,
            'reviewed_by' => $file->reviewed_by,
            'reviewed_at' => $file->reviewed_at?->toIso8601String(),
            'rejection_reason' => $file->rejection_reason,
            'created_at' => $file->created_at?->toIso8601String(),
        ];
    }

    private function documentDefinitionFields($def): array
    {
        return [
            'id' => $def->id,
            'name' => $def->name,
            'description' => $def->description,
            'is_required' => $def->is_required,
            'sort_order' => $def->sort_order,
        ];
    }

    private function auditLogFields(Client $client): array
    {
        return AuditLog::where('client_id', $client->id)->get()->map(fn ($log) => [
            'id' => $log->id,
            'action' => $log->action,
            'metadata' => $log->metadata,
            'created_at' => $log->created_at?->toIso8601String(),
        ])->all();
    }

    // -- Helpers ---------------------------------------------------------

    /**
     * Resolves a raw (unsigned) *_url column value back to a real path on
     * the 'public' disk and copies it into the archive under files/,
     * preserving the same relative path the app already stores it at
     * (e.g. files/contracts/contract-3-signed.pdf). Silently skips anything
     * that isn't one of our own /storage/... values (already-blank columns,
     * or the rare external link) — same tolerance App\Support\FileUrl::sign
     * has for the same reason.
     */
    private function copyStoredFile(?string $rawValue, string $filesDir): void
    {
        if (! $rawValue) {
            return;
        }

        $path = FileUrl::extractStoragePath($rawValue);
        if ($path === null || ! Storage::disk('public')->exists($path)) {
            return;
        }

        $dest = $filesDir.'/'.$path;
        File::ensureDirectoryExists(dirname($dest));
        File::copy(Storage::disk('public')->path($path), $dest);
    }

    /**
     * @return array<int, string>
     */
    private function decodeUrls(?string $raw): array
    {
        if (! $raw) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function encode(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Zips everything under $work, preserving relative paths, into
     * storage/app/private/exports/{$relativeDestPath}. Mirrors
     * App\Console\Commands\BackupDatabase::archive()'s approach.
     */
    private function zip(string $work, string $relativeDestPath): void
    {
        Storage::disk('local')->makeDirectory('exports');
        $destAbsolute = Storage::disk('local')->path($relativeDestPath);

        $zip = new \ZipArchive();
        if ($zip->open($destAbsolute, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Could not create export archive at {$destAbsolute}");
        }

        foreach (File::allFiles($work) as $file) {
            $zip->addFile($file->getPathname(), str_replace('\\', '/', $file->getRelativePathname()));
        }

        $zip->close();
    }
}
