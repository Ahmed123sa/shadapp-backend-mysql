<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\User;
use App\Models\Workspace;
use App\Mail\ContractSentMail;
use App\Mail\ContractCompanyApprovedMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileAndSignatureTest extends TestCase
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
            'email' => 'client@test.com',
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

    public function test_admin_can_save_text_signature(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/auth/sign', ['signature' => 'Admin Signature']);

        $response->assertStatus(200);
        $response->assertJsonPath('user.signature_data', 'Admin Signature');
        $this->assertNotNull($response->json('user.signed_at'));

        $this->assertDatabaseHas('users', [
            'id' => $this->admin->id,
            'signature_data' => 'Admin Signature',
        ]);
    }

    public function test_admin_can_save_image_signature(): void
    {
        $file = UploadedFile::fake()->image('signature.png', 200, 100);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/auth/sign', ['signature_image' => $file]);

        $response->assertStatus(200);
        $this->assertStringContainsString('signatures/', $response->json('user.signature_data'));
        $this->assertNotNull($response->json('user.signed_at'));

        $this->assertDatabaseHas('users', [
            'id' => $this->admin->id,
        ]);

        $savedPath = $response->json('user.signature_data');
        $relativePath = str_replace('/storage/', '', $savedPath);
        Storage::disk('public')->assertExists($relativePath);
    }

    public function test_admin_can_update_profile(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/auth/me', [
                'name' => 'Updated Admin',
                'official_email' => 'official@company.com',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('user.name', 'Updated Admin');
        $response->assertJsonPath('user.official_email', 'official@company.com');

        $this->assertDatabaseHas('users', [
            'id' => $this->admin->id,
            'name' => 'Updated Admin',
            'official_email' => 'official@company.com',
        ]);
    }

    public function test_admin_can_update_avatar(): void
    {
        $file = UploadedFile::fake()->image('avatar.png', 200, 200);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/auth/me', ['avatar' => $file]);

        $response->assertStatus(200);
        // avatar_url is now signed on every read (see App\Support\FileUrl), so
        // the response value is no longer the raw /storage/... path — assert
        // against the stored raw value instead, which the disk path is
        // actually derived from.
        $this->assertStringContainsString('avatars/', $response->json('user.avatar_url'));

        $rawPath = $this->admin->fresh()->getRawOriginal('avatar_url');
        $relativePath = str_replace('/storage/', '', $rawPath);
        Storage::disk('public')->assertExists($relativePath);
    }

    public function test_client_can_update_profile(): void
    {
        $file = UploadedFile::fake()->image('client_avatar.png', 200, 200);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/clients/{$this->client->id}/profile", [
                'contact_person' => 'Updated Person',
                'avatar' => $file,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('client.contact_person', 'Updated Person');
        $this->assertStringContainsString('avatars/', $response->json('client.avatar_url'));

        $this->assertDatabaseHas('clients', [
            'id' => $this->client->id,
            'contact_person' => 'Updated Person',
        ]);
    }

    public function test_client_can_update_profile_from_client_auth(): void
    {
        $token = $this->client->createToken('test')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->putJson("/api/clients/{$this->client->id}/profile", [
                'contact_person' => 'Client Self Update',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('client.contact_person', 'Client Self Update');
    }

    public function test_company_approve_uses_saved_signature(): void
    {
        $this->admin->update([
            'signature_data' => 'Saved Admin Signature',
            'signed_at' => now(),
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/contracts/{$this->contract->id}/company-approve", []);

        $response->assertStatus(200);
        $response->assertJsonPath('contract.company_signature_data', 'Saved Admin Signature');
    }

    public function test_company_approve_falls_back_to_name(): void
    {
        $this->admin->update(['signature_data' => null, 'signed_at' => null]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/contracts/{$this->contract->id}/company-approve", []);

        $response->assertStatus(200);
        $response->assertJsonPath('contract.company_signature_data', $this->admin->name);
    }

    public function test_company_approve_request_overrides_saved(): void
    {
        $this->admin->update([
            'signature_data' => 'Saved Admin Signature',
            'signed_at' => now(),
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/contracts/{$this->contract->id}/company-approve", [
                'signature' => 'Override Signature',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('contract.company_signature_data', 'Override Signature');
    }

    public function test_client_password_can_be_reset(): void
    {
        $response = $this->actingAs($this->manager, 'sanctum')
            ->putJson("/api/clients/{$this->client->id}", [
                'company_name' => $this->client->company_name,
                'password' => 'newpass123',
            ]);

        $response->assertStatus(200);

        $this->assertTrue(
            \Illuminate\Support\Facades\Hash::check('newpass123', $this->client->fresh()->password)
        );
    }

    public function test_official_email_receives_contract_sent_email(): void
    {
        Mail::fake();

        $this->admin->update(['official_email' => 'official@company.com']);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/contracts/{$this->contract->id}/send");

        $response->assertStatus(200);

        // assertQueued, not assertSent: these mailables implement ShouldQueue
        // so they are handed to the queue rather than delivered inline, and
        // Mail::fake() records them in the queued bucket accordingly.
        Mail::assertQueued(ContractSentMail::class, function ($mail) {
            return $mail->hasTo('official@company.com');
        });
    }

    public function test_official_email_receives_company_approved_email(): void
    {
        Mail::fake();

        $this->admin->update([
            'official_email' => 'official@company.com',
            'signature_data' => 'sig',
            'signed_at' => now(),
        ]);

        $this->contract->update(['status' => 'client_approved']);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/contracts/{$this->contract->id}/company-approve", []);

        $response->assertStatus(200);

        Mail::assertQueued(ContractCompanyApprovedMail::class, function ($mail) {
            return $mail->hasTo('official@company.com');
        });
    }

    public function test_auth_me_returns_new_fields(): void
    {
        $this->admin->update([
            'official_email' => 'official@company.com',
            'signature_data' => 'sig',
            'signed_at' => now(),
            'avatar_url' => '/storage/avatars/test.png',
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/auth/me');

        $response->assertStatus(200);
        $response->assertJsonStructure(['user' => [
            'official_email', 'signature_data', 'signed_at', 'avatar_url',
        ]]);
        $response->assertJsonPath('user.official_email', 'official@company.com');
        // avatar_url is now signed on every read (see App\Support\FileUrl), so
        // the response is no longer the raw stored path verbatim — just the
        // same path signed, which this checks by substring.
        $this->assertStringContainsString('avatars/test.png', $response->json('user.avatar_url'));
    }
}
