<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Workspace;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression suite for plans/payment-currency-plan.md: a payment's currency
 * must always match the currency of the contract it belongs to (or the
 * workspace's contracts when no single contract is linked yet), never
 * whatever the client/manager's request happened to send. Before this fix,
 * store()/schedule()/requestPayment() all did `$request->currency ?? 'SAR'`
 * with zero validation against the linked contract, so a client on an EGP
 * contract could (and in production, did) end up with a payment recorded
 * in SAR.
 */
class PaymentCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private User $superAdmin;
    private Client $client;
    private Workspace $workspace;

    private function resetAuth(): void
    {
        $this->app->make('auth')->forgetGuards();
    }

    private function actingAsClient(Client $client): self
    {
        $this->resetAuth();
        return $this->actingAs($client, 'client');
    }

    private function actingAsManager(User $user): self
    {
        $this->resetAuth();
        return $this->actingAs($user);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $this->manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER, 'super_admin_id' => $this->superAdmin->id]);
        $this->client = Client::factory()->create(['manager_id' => $this->manager->id]);
        $this->workspace = Workspace::factory()->create(['client_id' => $this->client->id, 'manager_id' => $this->manager->id]);
    }

    private function payableContract(string $currency, int $value = 10000): Contract
    {
        return Contract::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->manager->id,
            'status' => 'company_approved',
            'currency' => $currency,
            'value' => $value,
        ]);
    }

    // ─── store() ───────────────────────────────────────────────

    public function test_store_uses_the_linked_contracts_currency_when_none_is_requested(): void
    {
        $contract = $this->payableContract('EGP');

        $response = $this->actingAsClient($this->client)
            ->postJson("/api/workspaces/{$this->workspace->id}/payments", [
                'amount' => 2000,
                'method_type' => 'bank_transfer',
                'contract_id' => $contract->id,
            ]);

        $response->assertStatus(201);
        $this->assertEquals('EGP', $response->json('payment.currency'));
    }

    public function test_store_overrides_a_mismatched_requested_currency_with_the_contracts(): void
    {
        $contract = $this->payableContract('EGP');

        $response = $this->actingAsClient($this->client)
            ->postJson("/api/workspaces/{$this->workspace->id}/payments", [
                'amount' => 2000,
                'method_type' => 'bank_transfer',
                'contract_id' => $contract->id,
                'currency' => 'SAR',
            ]);

        $response->assertStatus(201);
        $this->assertEquals('EGP', $response->json('payment.currency'));
    }

    public function test_store_uses_the_workspaces_single_contract_currency_when_no_contract_is_auto_linked(): void
    {
        // A contract that exists but isn't payable (not company_approved/
        // completed) so store() won't auto-link it — the currency should
        // still be derived from it since it's the workspace's only contract.
        Contract::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->manager->id,
            'status' => 'draft',
            'currency' => 'USD',
        ]);

        $response = $this->actingAsClient($this->client)
            ->postJson("/api/workspaces/{$this->workspace->id}/payments", [
                'amount' => 500,
                'method_type' => 'bank_transfer',
            ]);

        $response->assertStatus(201);
        $this->assertEquals('USD', $response->json('payment.currency'));
        $this->assertNull($response->json('payment.contract_id'));
    }

    public function test_store_rejects_ambiguous_currency_when_workspace_has_multiple_contract_currencies_and_none_selected(): void
    {
        // Neither contract is company_approved/completed, so store() has
        // nothing to auto-link — this is the only way store() can reach a
        // genuinely ambiguous case (an explicit contract_id is otherwise
        // always required to be payable, and a payable contract would have
        // been auto-linked, resolving the currency via case 1).
        Contract::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->manager->id,
            'status' => 'draft',
            'currency' => 'EGP',
        ]);
        Contract::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->manager->id,
            'status' => 'draft',
            'currency' => 'USD',
        ]);

        $response = $this->actingAsClient($this->client)
            ->postJson("/api/workspaces/{$this->workspace->id}/payments", [
                'amount' => 500,
                'method_type' => 'bank_transfer',
            ]);

        $response->assertStatus(422);
    }

    public function test_store_accepts_a_requested_currency_matching_one_of_several_contract_currencies(): void
    {
        Contract::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->manager->id,
            'status' => 'draft',
            'currency' => 'EGP',
        ]);
        Contract::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->manager->id,
            'status' => 'draft',
            'currency' => 'USD',
        ]);

        // No contract to link to, but the requested currency matches one of
        // the workspace's contract currencies, so it's not ambiguous.
        $response = $this->actingAsClient($this->client)
            ->postJson("/api/workspaces/{$this->workspace->id}/payments", [
                'amount' => 500,
                'method_type' => 'bank_transfer',
                'currency' => 'USD',
            ]);

        $response->assertStatus(201);
        $this->assertEquals('USD', $response->json('payment.currency'));
        $this->assertNull($response->json('payment.contract_id'));
    }

    public function test_store_falls_back_to_requested_currency_when_workspace_has_no_contracts_at_all(): void
    {
        $response = $this->actingAsClient($this->client)
            ->postJson("/api/workspaces/{$this->workspace->id}/payments", [
                'amount' => 500,
                'method_type' => 'bank_transfer',
                'currency' => 'AED',
            ]);

        $response->assertStatus(201);
        $this->assertEquals('AED', $response->json('payment.currency'));
    }

    public function test_store_defaults_to_sar_when_workspace_has_no_contracts_and_no_currency_requested(): void
    {
        $response = $this->actingAsClient($this->client)
            ->postJson("/api/workspaces/{$this->workspace->id}/payments", [
                'amount' => 500,
                'method_type' => 'bank_transfer',
            ]);

        $response->assertStatus(201);
        $this->assertEquals('SAR', $response->json('payment.currency'));
    }

    // ─── update() ──────────────────────────────────────────────

    public function test_update_overrides_a_mismatched_currency_to_match_the_payments_contract(): void
    {
        $contract = $this->payableContract('EGP');
        $payment = Payment::factory()->create([
            'workspace_id' => $this->workspace->id,
            'client_id' => $this->client->id,
            'contract_id' => $contract->id,
            'currency' => 'EGP',
            'status' => 'pending',
        ]);

        $response = $this->actingAsClient($this->client)
            ->putJson("/api/workspaces/{$this->workspace->id}/payments/{$payment->id}", [
                'currency' => 'SAR',
            ]);

        $response->assertOk();
        $this->assertEquals('EGP', $payment->fresh()->currency);
    }

    // ─── schedule() ────────────────────────────────────────────

    public function test_schedule_uses_the_given_installments_contract_currency(): void
    {
        $egp = $this->payableContract('EGP');
        Contract::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->manager->id,
            'status' => 'draft',
            'currency' => 'USD',
        ]);

        $response = $this->actingAsManager($this->manager)
            ->postJson("/api/workspaces/{$this->workspace->id}/payments/schedule", [
                'installments' => [[
                    'amount' => 100,
                    'due_date' => now()->addDays(5)->toDateString(),
                    'contract_id' => $egp->id,
                    'currency' => 'SAR',
                ]],
            ]);

        $response->assertStatus(201);
        $this->assertEquals('EGP', $response->json('payments.0.currency'));
        $this->assertEquals($egp->id, $response->json('payments.0.contract_id'));
    }

    public function test_schedule_rejects_ambiguous_currency_without_a_contract_id(): void
    {
        $this->payableContract('EGP');
        Contract::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->manager->id,
            'status' => 'draft',
            'currency' => 'USD',
        ]);

        $response = $this->actingAsManager($this->manager)
            ->postJson("/api/workspaces/{$this->workspace->id}/payments/schedule", [
                'installments' => [[
                    'amount' => 100,
                    'due_date' => now()->addDays(5)->toDateString(),
                ]],
            ]);

        $response->assertStatus(422);
    }

    public function test_schedule_uses_the_workspaces_single_contract_currency_without_a_contract_id(): void
    {
        $this->payableContract('USD');

        $response = $this->actingAsManager($this->manager)
            ->postJson("/api/workspaces/{$this->workspace->id}/payments/schedule", [
                'installments' => [[
                    'amount' => 100,
                    'due_date' => now()->addDays(5)->toDateString(),
                ]],
            ]);

        $response->assertStatus(201);
        $this->assertEquals('USD', $response->json('payments.0.currency'));
    }

    // ─── requestPayment() ──────────────────────────────────────

    public function test_request_payment_uses_the_given_contracts_currency(): void
    {
        $egp = $this->payableContract('EGP');

        $response = $this->actingAsManager($this->manager)
            ->postJson("/api/workspaces/{$this->workspace->id}/payments/request", [
                'amount' => 100,
                'contract_id' => $egp->id,
                'currency' => 'SAR',
            ]);

        $response->assertStatus(201);
        $this->assertEquals('EGP', $response->json('payment.currency'));
    }

    public function test_request_payment_rejects_a_contract_id_from_another_workspace(): void
    {
        $otherWorkspace = Workspace::factory()->create();
        $foreign = Contract::factory()->create(['workspace_id' => $otherWorkspace->id]);

        $response = $this->actingAsManager($this->manager)
            ->postJson("/api/workspaces/{$this->workspace->id}/payments/request", [
                'amount' => 100,
                'contract_id' => $foreign->id,
            ]);

        $response->assertStatus(422);
    }
}
