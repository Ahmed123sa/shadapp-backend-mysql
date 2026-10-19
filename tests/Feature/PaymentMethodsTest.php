<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentMethodsTest extends TestCase
{
    use RefreshDatabase;

    private const ALL = ['bank_transfer', 'swift', 'corporate_account', 'instapay', 'vodafone_cash', 'mobile_wallet', 'cheque'];

    #[\PHPUnit\Framework\Attributes\DataProvider('clientTypes')]
    public function test_every_client_type_gets_every_payment_method(string $type): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $client = Client::factory()->create(['client_type' => $type]);
        $workspace = Workspace::factory()->create(['client_id' => $client->id]);

        $response = $this->actingAs($admin)->getJson("/api/workspaces/{$workspace->id}/payments");

        $response->assertOk();
        $this->assertEqualsCanonicalizing(self::ALL, $response->json('available_methods'));
    }

    public static function clientTypes(): array
    {
        return ['business' => ['business'], 'individual' => ['individual']];
    }
}
