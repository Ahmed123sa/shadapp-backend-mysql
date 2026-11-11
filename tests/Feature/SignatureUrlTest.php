<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Support\SignatureValue;
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

    public function test_live_relative_signature_path_gets_signed_url_for_supported_image_extensions(): void
    {
        foreach (['png', 'jpg', 'jpeg', 'webp'] as $ext) {
            $path = "/storage/signatures/live-sig.{$ext}";
            $this->assertTrue(SignatureValue::isImage($path), "Expected {$path} to be treated as an image");

            $client = Client::factory()->create(['signature_data' => $path]);
            $url = $client->toArray()['signature_url'];

            $this->assertNotNull($url);
            $this->assertStringContainsString("/files/signatures/live-sig.{$ext}", $url);
            $this->assertStringContainsString('signature=', $url);
        }
    }

    public function test_non_signature_storage_path_never_gets_a_signed_url(): void
    {
        $dangerousValues = [
            '/storage/contracts/contract-1-signed.pdf',
            '/storage/payments/proof-1.png',
            '/storage/signatures/../contracts/contract-1-signed.pdf',
            '/storage/signatures/sub/nested.png',
            '/storage/signatures/evil.svg',
            '/storage/signatures/shell.php',
            'https://evil.example.com/storage/signatures/c.png',
            '/storage/signatures/c.png?foo=bar',
        ];

        foreach ($dangerousValues as $value) {
            $this->assertFalse(SignatureValue::isImage($value), "Expected {$value} to be rejected");
            $this->assertNull(SignatureValue::diskPath($value), "Expected null diskPath for {$value}");

            $client = Client::factory()->create(['signature_data' => $value]);
            $this->assertNull($client->toArray()['signature_url'], "Expected null signature_url for {$value}");
        }
    }

    public function test_disk_path_resolves_real_signature_image_and_rejects_outside_paths(): void
    {
        Storage::disk('public')->put('signatures/valid.png', 'png-bytes');
        Storage::disk('public')->put('contracts/contract-1-signed.pdf', 'pdf-bytes');

        $resolved = SignatureValue::diskPath('/storage/signatures/valid.png');
        $this->assertNotNull($resolved);
        $this->assertFileExists($resolved);

        $this->assertNull(SignatureValue::diskPath('/storage/contracts/contract-1-signed.pdf'));
        $this->assertNull(SignatureValue::diskPath('/storage/signatures/../contracts/contract-1-signed.pdf'));
        $this->assertNull(SignatureValue::diskPath('/storage/signatures/missing.png'));
    }
}
