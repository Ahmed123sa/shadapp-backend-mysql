<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 23 Sept 2026 — uploaded signature images were previewed straight from
 * /storage/..., which doesn't exist in production (no storage:link), so the
 * preview never loaded. Users and clients now carry a signed signature_url
 * for display; signature_data stays the raw stored value.
 */
class SignatureUrlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_an_uploaded_signature_gets_a_signed_url_that_loads(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/auth/sign', ['signature_image' => UploadedFile::fake()->image('sig.png', 200, 100)])
            ->assertOk();

        $raw = $response->json('user.signature_data');
        $url = $response->json('user.signature_url');
        $this->assertStringContainsString('/storage/signatures/', $raw);
        $this->assertStringContainsString('/files/signatures/', $url);
        $this->assertStringContainsString('signature=', $url);

        $this->get($url)->assertOk();
    }

    public function test_a_typed_signature_has_no_url(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN, 'signature_data' => 'Ahmed']);

        $this->assertNull($admin->toArray()['signature_url']);
        $this->assertSame('Ahmed', $admin->toArray()['signature_data']);
    }

    public function test_a_client_gets_the_same_signed_url(): void
    {
        $client = Client::factory()->create(['signature_data' => 'http://localhost/storage/signatures/c.png']);

        $data = $client->toArray();
        $this->assertSame('http://localhost/storage/signatures/c.png', $data['signature_data']);
        $this->assertStringContainsString('/files/signatures/c.png', $data['signature_url']);
    }
}
