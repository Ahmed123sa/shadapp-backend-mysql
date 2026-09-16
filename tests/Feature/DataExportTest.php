<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Contract;
use App\Models\DataExport;
use App\Models\FileEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Mirrors tests/Feature/DataExportTest.php from shadapp-backend (Postgres)
 * verbatim — no database-specific code involved.
 *
 * Covers DATA_SAFETY_PLAN.md §3 — scoped, authorized data exports. Requests
 * run through the real HTTP route; since phpunit.xml sets
 * QUEUE_CONNECTION=sync, App\Jobs\GenerateDataExport actually executes
 * inline during that same request, so a 'client'/'manager' scope test can
 * immediately inspect the finished archive afterward with no extra wiring.
 *
 * 'system' scope only ever gets exercised as far as "was the request
 * authorized" — the archive itself is built by re-running db:backup
 * (App\Services\DataExportService::buildSystemArchive), which requires a
 * real mysqldump/pg_dump against a mysql/pgsql connection and therefore
 * cannot actually succeed against this suite's sqlite :memory: database.
 * That failure is caught inside the job itself (status becomes 'failed'
 * with the driver error recorded) rather than surfacing here — see the
 * job's own docblock for why it never rethrows.
 */
class DataExportTest extends TestCase
{
    use RefreshDatabase;

    private function makeClientWithWorkspace(User $manager, array $clientOverrides = []): array
    {
        $client = Client::factory()->create(array_merge(['manager_id' => $manager->id], $clientOverrides));
        $workspace = Workspace::factory()->create([
            'client_id' => $client->id,
            'manager_id' => $manager->id,
        ]);

        return [$client, $workspace];
    }

    /**
     * @return array{0: \ZipArchive, 1: string} the open archive and the
     * absolute path it was opened from (caller must close it).
     */
    private function openArchiveFor(int $exportId): array
    {
        $export = DataExport::findOrFail($exportId);
        $this->assertSame(DataExport::STATUS_READY, $export->status, $export->error ?? 'export did not reach ready status');

        $absolutePath = Storage::disk('local')->path($export->file_path);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($absolutePath) === true);

        return [$zip, $absolutePath];
    }

    public function test_super_admin_can_request_all_three_scopes(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        [$client] = $this->makeClientWithWorkspace($manager);

        $system = $this->actingAs($admin, 'sanctum')->postJson('/api/data-exports', ['scope' => 'system']);
        $system->assertStatus(202);

        $managerScope = $this->actingAs($admin, 'sanctum')->postJson('/api/data-exports', ['scope' => 'manager', 'scope_id' => $manager->id]);
        $managerScope->assertStatus(202);

        $clientScope = $this->actingAs($admin, 'sanctum')->postJson('/api/data-exports', ['scope' => 'client', 'scope_id' => $client->id]);
        $clientScope->assertStatus(202);

        $this->assertSame(3, DataExport::count());
    }

    public function test_manager_can_request_manager_scope_for_themselves(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);

        $response = $this->actingAs($manager, 'sanctum')->postJson('/api/data-exports', [
            'scope' => 'manager',
            'scope_id' => $manager->id,
        ]);

        $response->assertStatus(202);
    }

    public function test_manager_cannot_request_manager_scope_for_another_manager(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $otherManager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);

        $response = $this->actingAs($manager, 'sanctum')->postJson('/api/data-exports', [
            'scope' => 'manager',
            'scope_id' => $otherManager->id,
        ]);

        $response->assertStatus(403);
    }

    public function test_manager_can_request_client_scope_for_their_own_client(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        [$client] = $this->makeClientWithWorkspace($manager);

        $response = $this->actingAs($manager, 'sanctum')->postJson('/api/data-exports', [
            'scope' => 'client',
            'scope_id' => $client->id,
        ]);

        $response->assertStatus(202);
    }

    public function test_manager_cannot_request_client_scope_for_a_client_that_is_not_theirs(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $otherManager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        [$client] = $this->makeClientWithWorkspace($otherManager);

        $response = $this->actingAs($manager, 'sanctum')->postJson('/api/data-exports', [
            'scope' => 'client',
            'scope_id' => $client->id,
        ]);

        $response->assertStatus(403);
    }

    public function test_manager_cannot_request_system_scope(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);

        $response = $this->actingAs($manager, 'sanctum')->postJson('/api/data-exports', ['scope' => 'system']);

        $response->assertStatus(403);
    }

    public function test_client_archive_contains_every_table_in_the_client_tree(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        [$client, $workspace] = $this->makeClientWithWorkspace($manager);
        Contract::factory()->create(['workspace_id' => $workspace->id, 'created_by' => $manager->id]);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/data-exports', [
            'scope' => 'client',
            'scope_id' => $client->id,
        ]);
        $response->assertStatus(202);

        [$zip] = $this->openArchiveFor($response->json('export.id'));

        foreach (['client.json', 'sub_users.json', 'audit_logs.json', 'workspace.json', 'contracts.json', 'payments.json', 'approvals.json', 'meetings.json', 'chat_messages.json', 'files.json', 'document_definitions.json'] as $entry) {
            $this->assertNotFalse($zip->locateName($entry), "Archive is missing {$entry}");
        }

        $contracts = json_decode($zip->getFromName('contracts.json'), true);
        $this->assertCount(1, $contracts);

        $zip->close();
    }

    public function test_client_archive_never_contains_a_password_or_token(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        [$client] = $this->makeClientWithWorkspace($manager, ['password' => 'Password1']);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/data-exports', [
            'scope' => 'client',
            'scope_id' => $client->id,
        ]);
        $response->assertStatus(202);

        [$zip] = $this->openArchiveFor($response->json('export.id'));

        $clientJson = $zip->getFromName('client.json');
        $this->assertStringNotContainsString('password', $clientJson);
        $this->assertStringNotContainsString('Password1', $clientJson);
        $this->assertStringNotContainsString('remember_token', $clientJson);

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $this->assertStringNotContainsStringIgnoringCase('personal_access_tokens', $zip->getNameIndex($i));
        }

        $zip->close();
    }

    public function test_download_without_a_valid_signature_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        [$client] = $this->makeClientWithWorkspace($manager);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/data-exports', [
            'scope' => 'client',
            'scope_id' => $client->id,
        ]);
        $exportId = $response->json('export.id');

        // Hit the download route with no signature at all — an attacker who
        // simply guesses the numeric id, with no valid link ever handed out.
        $unsigned = $this->get("/exports/{$exportId}/download");

        $unsigned->assertStatus(403);
    }

    public function test_download_with_an_expired_link_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        [$client] = $this->makeClientWithWorkspace($manager);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/data-exports', [
            'scope' => 'client',
            'scope_id' => $client->id,
        ]);
        $exportId = $response->json('export.id');

        // A signed URL that already expired a minute ago — the 'signed'
        // middleware itself must reject this before the controller ever runs.
        $expiredUrl = URL::temporarySignedRoute('exports.download', now()->subMinute(), ['dataExport' => $exportId]);

        $expired = $this->get($expiredUrl);

        $expired->assertStatus(403);
    }

    public function test_every_request_is_recorded_in_audit_logs(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        [$client] = $this->makeClientWithWorkspace($manager);

        $this->actingAs($admin, 'sanctum')->postJson('/api/data-exports', [
            'scope' => 'client',
            'scope_id' => $client->id,
        ]);

        $log = AuditLog::where('action', 'data_export.requested')->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame('client', $log->metadata['scope']);
        $this->assertSame($client->id, $log->metadata['scope_id']);
    }

    public function test_uploaded_files_are_present_under_files_in_the_archive(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('contracts/test-export-file.pdf', 'fake-pdf-content');

        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        [$client, $workspace] = $this->makeClientWithWorkspace($manager);

        FileEntry::create([
            'workspace_id' => $workspace->id,
            'uploaded_by_type' => User::class,
            'uploaded_by_id' => $manager->id,
            'file_url' => Storage::disk('public')->url('contracts/test-export-file.pdf'),
            'name' => 'test-export-file.pdf',
            'type' => 'application/pdf',
            'size' => 123,
            'status' => 'approved',
        ]);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/data-exports', [
            'scope' => 'client',
            'scope_id' => $client->id,
        ]);
        $response->assertStatus(202);

        [$zip] = $this->openArchiveFor($response->json('export.id'));

        $this->assertNotFalse($zip->locateName('files/contracts/test-export-file.pdf'));
        $this->assertSame('fake-pdf-content', $zip->getFromName('files/contracts/test-export-file.pdf'));

        $zip->close();
    }
}
