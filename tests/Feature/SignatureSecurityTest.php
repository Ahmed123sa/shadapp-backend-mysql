<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SignatureSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $manager;
    private Client $client;
    private Workspace $workspace;
    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->admin = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'name' => 'Admin User',
        ]);

        $this->manager = User::factory()->create([
            'role' => User::ROLE_ACCOUNT_MANAGER,
            'super_admin_id' => $this->admin->id,
            'name' => 'Manager User',
        ]);

        $this->client = Client::factory()->create([
            'manager_id' => $this->manager->id,
            'contact_person' => 'Client Person',
        ]);

        $this->workspace = Workspace::factory()->create([
            'client_id' => $this->client->id,
            'manager_id' => $this->manager->id,
        ]);

        $this->contract = Contract::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->manager->id,
            'status' => 'client_approved',
        ]);
    }

    public function test_client_cannot_save_path_or_url_as_typed_signature(): void
    {
        $token = $this->client->createToken('test')->plainTextToken;

        $payloads = [
            '/storage/contracts/contract-1-signed.pdf',
            '/storage/payments/proof-1.png',
            'http://localhost/storage/contracts/contract-1-signed.pdf',
            'https://api.shadmanagement.co/storage/contracts/contract-1-signed.pdf',
            'foo/storage/contracts/contract-1-signed.pdf',
            'storage/contracts/contract-1-signed.pdf',
            '../contracts/contract-1-signed.pdf',
            '/etc/passwd',
            'data:image/png;base64,AAAA',
            str_repeat('A', 201),
        ];

        foreach ($payloads as $payload) {
            $this->withHeaders(['Authorization' => 'Bearer ' . $token])
                ->postJson("/api/clients/{$this->client->id}/sign", ['signature' => $payload])
                ->assertStatus(422);

            $this->assertNull($this->client->fresh()->signature_data, "Payload was unexpectedly saved: {$payload}");
        }
    }

    public function test_client_can_save_valid_typed_name_and_uploaded_image_signature(): void
    {
        $token = $this->client->createToken('test')->plainTextToken;

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson("/api/clients/{$this->client->id}/sign", ['signature' => 'أحمد محمد الشاذلي'])
            ->assertOk()
            ->assertJsonPath('client.signature_data', 'أحمد محمد الشاذلي')
            ->assertJsonPath('client.signature_url', null);

        $file = UploadedFile::fake()->image('sig.jpg', 120, 60);
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson("/api/clients/{$this->client->id}/sign", ['signature_image' => $file])
            ->assertOk();

        $this->assertStringStartsWith('/storage/signatures/', $response->json('client.signature_data'));
        $this->assertStringContainsString('/files/signatures/', $response->json('client.signature_url'));
    }

    public function test_staff_cannot_save_path_or_url_as_typed_signature(): void
    {
        $payloads = [
            '/storage/contracts/contract-1-signed.pdf',
            'https://api.shadmanagement.co/storage/contracts/contract-1-signed.pdf',
            'http://localhost/storage/signatures/a.png',
            '../contracts/contract-1-signed.pdf',
            str_repeat('B', 201),
        ];

        foreach ($payloads as $payload) {
            $this->actingAs($this->admin, 'sanctum')
                ->postJson('/api/auth/sign', ['signature' => $payload])
                ->assertStatus(422);

            $this->assertNull($this->admin->fresh()->signature_data, "Payload was unexpectedly saved: {$payload}");
        }
    }

    public function test_company_approve_rejects_path_injection_in_signature_param(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/contracts/{$this->contract->id}/company-approve", [
                'signature' => '/storage/contracts/contract-1-signed.pdf',
            ])
            ->assertStatus(422);

        $this->assertSame('client_approved', $this->contract->fresh()->status);
    }

    public function test_company_approve_works_with_saved_image_signature_and_typed_name(): void
    {
        Mail::fake();

        Storage::disk('public')->put('signatures/admin-saved.png', base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='
        ));

        $this->admin->update([
            'signature_data' => '/storage/signatures/admin-saved.png',
            'signed_at' => now(),
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/contracts/{$this->contract->id}/company-approve", [
                'use_saved_signature' => true,
            ])
            ->assertOk()
            ->assertJsonPath('contract.company_signature_data', '/storage/signatures/admin-saved.png')
            ->assertJsonPath('contract.company_signature_type', 'image');

        $secondContract = Contract::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->manager->id,
            'status' => 'client_approved',
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/contracts/{$secondContract->id}/company-approve", [
                'signature' => 'Shad Management Co.',
            ])
            ->assertOk()
            ->assertJsonPath('contract.company_signature_data', 'Shad Management Co.')
            ->assertJsonPath('contract.company_signature_type', 'text');
    }

    public function test_signatures_audit_command_passes_when_clean_and_fails_on_suspicious_values(): void
    {
        $this->admin->update(['signature_data' => '/storage/signatures/admin-clean.png']);
        $this->client->update(['signature_data' => 'Ahmed Ali']);

        $this->artisan('signatures:audit')->assertSuccessful();

        DB::table('clients')->where('id', $this->client->id)->update([
            'signature_data' => '/storage/contracts/contract-9-signed.pdf',
        ]);

        $this->artisan('signatures:audit')->assertFailed();
    }
}
