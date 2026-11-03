<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The tax % belongs to the client and is frozen on each contract the moment
 * the client approves it — changing it later must never re-price a signed one.
 */
class ClientTaxPerContractTest extends TestCase
{
    use RefreshDatabase;

    private User $am;
    private Client $client;
    private Workspace $ws;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::setValue('corporate_tax_percentage', 14);
        $this->am = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $this->client = Client::factory()->create([
            'manager_id' => $this->am->id,
            'client_type' => 'business',
            'tax_percentage' => 12,
            'signature_data' => 'Client Name',
        ]);
        $this->ws = Workspace::factory()->create(['client_id' => $this->client->id, 'manager_id' => $this->am->id]);
    }

    private function resetAuth(): void
    {
        $this->app->make('auth')->forgetGuards();
    }

    private function asClient(): static
    {
        $token = $this->client->createToken('t')->plainTextToken;
        $this->resetAuth();
        $this->defaultHeaders = [];

        return $this->withHeaders(['Authorization' => 'Bearer ' . $token]);
    }

    private function asUser(User $u): static
    {
        $this->resetAuth();
        $this->defaultHeaders = [];

        return $this->actingAs($u);
    }

    private function contract(string $status, float $value = 1000): Contract
    {
        return Contract::factory()->create([
            'workspace_id' => $this->ws->id,
            'created_by' => $this->am->id,
            'status' => $status,
            'value' => $value,
        ])->fresh();
    }

    private function approve(Contract $c): void
    {
        $this->asClient()
            ->postJson("/api/contracts/{$c->id}/client-action", ['action' => 'approved'])
            ->assertOk();
    }

    public function test_new_business_client_gets_the_global_percentage_by_default(): void
    {
        $res = $this->asUser($this->am)->postJson('/api/clients', [
            'company_name' => 'Acme', 'contact_person' => 'A', 'email' => 'acme@example.com',
            'phone' => '0100000000', 'client_type' => 'business',
        ])->assertCreated();

        $this->assertEquals(14, Client::find($res->json('client.id'))->tax_percentage);
    }

    public function test_individual_client_has_no_tax(): void
    {
        $res = $this->asUser($this->am)->postJson('/api/clients', [
            'company_name' => 'Ind', 'contact_person' => 'I', 'email' => 'ind@example.com',
            'phone' => '0100000001', 'client_type' => 'individual',
        ])->assertCreated();

        $this->assertNull(Client::find($res->json('client.id'))->tax_percentage);
        $c = $this->contract('sent');
        $this->ws->client->update(['client_type' => 'individual']);
        $this->assertEquals(0, $c->fresh()->tax_amount);
    }

    public function test_approval_locks_the_percentage_and_a_later_change_does_not_touch_it(): void
    {
        $c = $this->contract('sent');
        $this->approve($c);
        $this->assertEquals(12, $c->fresh()->tax_percentage);

        $this->asUser($this->am)->putJson("/api/clients/{$this->client->id}", ['tax_percentage' => 20])->assertOk();
        $this->assertEquals(20, $this->client->fresh()->tax_percentage);

        $fresh = $c->fresh();
        $this->assertEquals(12, $fresh->tax_percentage);
        $this->assertEquals(120, $fresh->tax_amount);
        $this->assertEquals(1120, $fresh->total_with_tax);
    }

    public function test_a_contract_sent_after_the_change_takes_the_new_percentage_and_totals_add_up(): void
    {
        $old = $this->contract('sent', 1000);
        $this->approve($old);
        $this->asUser($this->am)->putJson("/api/clients/{$this->client->id}", ['tax_percentage' => 14])->assertOk();

        $new = $this->contract('sent', 500);
        $this->approve($new);

        $this->assertEquals(12, $old->fresh()->tax_percentage);
        $this->assertEquals(14, $new->fresh()->tax_percentage);

        $sum = \App\Support\WorkspaceTaxSummary::for($this->ws->fresh(), ['client_approved']);
        $this->assertEquals(1500, $sum['contracts_total']);
        $this->assertEquals(190, $sum['tax_amount']); // 120 + 70
        $this->assertEquals(1690, $sum['grand_total']);
        $this->assertCount(2, $sum['contracts']);
    }

    public function test_edit_request_unlocks_and_resend_follows_the_current_percentage(): void
    {
        $c = $this->contract('sent');
        $this->approve($c);
        $this->asUser($this->am)->putJson("/api/clients/{$this->client->id}", ['tax_percentage' => 5])->assertOk();

        $this->asClient()
            ->postJson("/api/contracts/{$c->id}/client-action", ['action' => 'edit_requested', 'reason' => 'x'])
            ->assertOk();
        $this->assertNull($c->fresh()->getRawOriginal('tax_percentage'));
        $this->assertEquals(5, $c->fresh()->tax_percentage);
    }

    public function test_only_super_admin_and_own_manager_can_change_the_percentage(): void
    {
        $this->asClient()
            ->putJson("/api/clients/{$this->client->id}", ['tax_percentage' => 0])->assertForbidden();

        $other = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $this->asUser($other)
            ->putJson("/api/clients/{$this->client->id}", ['tax_percentage' => 0])->assertForbidden();

        $super = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $this->asUser($super)
            ->putJson("/api/clients/{$this->client->id}", ['tax_percentage' => 9])->assertOk();
        $this->assertEquals(9, $this->client->fresh()->tax_percentage);
    }

    public function test_the_percentage_must_be_between_0_and_100_and_is_audited(): void
    {
        $this->asUser($this->am)
            ->putJson("/api/clients/{$this->client->id}", ['tax_percentage' => 101])->assertStatus(422);
        $this->asUser($this->am)
            ->putJson("/api/clients/{$this->client->id}", ['tax_percentage' => 7])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'client.tax_updated', 'auditable_id' => $this->client->id]);
    }

    public function test_payments_endpoint_keeps_old_keys_and_adds_the_breakdown(): void
    {
        $c = $this->contract('company_approved', 1000);
        $c->update(['tax_percentage' => 12]);

        $res = $this->asClient()->getJson("/api/workspaces/{$this->ws->id}/payments")->assertOk();
        $res->assertJsonStructure(['tax_summary' => ['contracts_total', 'tax_percentage', 'tax_amount', 'grand_total', 'contracts' => [['id', 'value', 'tax_percentage', 'tax_amount', 'total']]]]);
        $this->assertEquals(120, $res->json('tax_summary.tax_amount'));
    }
}
