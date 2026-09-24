<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 23 Sept 2026 — GET /clients carries has_signed_contract: whether the
 * client has approved at least one contract. Mobile's client cards used
 * signed_at (the client saving a profile signature) as "contracted".
 */
class ClientHasSignedContractTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private Client $client;
    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $this->client = Client::factory()->create(['manager_id' => $this->manager->id, 'signed_at' => null]);
        $this->workspace = Workspace::factory()->create([
            'client_id' => $this->client->id,
            'manager_id' => $this->manager->id,
        ]);
    }

    private function flag(): mixed
    {
        return collect($this->actingAs($this->manager)->getJson('/api/clients')->assertOk()->json('clients.data'))
            ->firstWhere('id', $this->client->id)['has_signed_contract'];
    }

    public function test_a_client_with_no_contracts_is_not_contracted(): void
    {
        $this->assertFalse($this->flag());
    }

    public function test_a_draft_or_sent_contract_does_not_count(): void
    {
        Contract::factory()->create(['workspace_id' => $this->workspace->id, 'status' => 'sent']);

        $this->assertFalse($this->flag());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('signedStatuses')]
    public function test_an_approved_contract_counts_even_without_a_saved_profile_signature(string $status): void
    {
        Contract::factory()->create(['workspace_id' => $this->workspace->id, 'status' => $status]);

        $this->assertTrue($this->flag());
    }

    public static function signedStatuses(): array
    {
        return [['client_approved'], ['company_approved'], ['completed']];
    }

    public function test_another_clients_contract_does_not_count(): void
    {
        $other = Client::factory()->create(['manager_id' => $this->manager->id]);
        $otherWs = Workspace::factory()->create(['client_id' => $other->id, 'manager_id' => $this->manager->id]);
        Contract::factory()->create(['workspace_id' => $otherWs->id, 'status' => 'completed']);

        $this->assertFalse($this->flag());
    }
}
