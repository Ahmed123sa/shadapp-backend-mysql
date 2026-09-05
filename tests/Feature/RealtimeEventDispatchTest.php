<?php

namespace Tests\Feature;

use App\Events\ContractStatusChanged;
use App\Events\PaymentStatusChanged;
use App\Events\WorkspaceStatusChanged;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Payment;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Stage 1 of REALTIME_PLAN.md — verifies the three new broadcast events
 * (ContractStatusChanged, WorkspaceStatusChanged, PaymentStatusChanged) fire
 * from every call-site listed in plan section 3, on top of the existing
 * notification/email events (which RealtimeBroadcastCharacterizationTest and
 * PaymentReviewActivationCharacterizationTest continue to guard unchanged).
 *
 * Mirrored from shadapp-backend (the Postgres copy) — this is the MySQL
 * copy kept in sync per the project's established dual-backend convention.
 */
class RealtimeEventDispatchTest extends TestCase
{
    use RefreshDatabase;

    private function makeWorkspace(string $status = 'inactive'): array
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $client = Client::factory()->create(['manager_id' => $manager->id]);
        $workspace = Workspace::factory()->create([
            'client_id' => $client->id,
            'manager_id' => $manager->id,
            'status' => $status,
        ]);

        return [$manager, $client, $workspace];
    }

    // --- broadcastOn/broadcastAs shape ------------------------------------

    public function test_contract_status_changed_broadcasts_on_private_workspace_channel(): void
    {
        $contract = new Contract(['workspace_id' => 5, 'status' => 'sent', 'contract_type' => 'main']);
        $event = new ContractStatusChanged($contract);

        $channels = $event->broadcastOn();
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertEquals('private-workspace.5', $channels[0]->name);
        $this->assertEquals('contract.status_changed', $event->broadcastAs());
    }

    public function test_workspace_status_changed_broadcasts_on_private_workspace_channel(): void
    {
        $workspace = new Workspace(['status' => 'active']);
        $workspace->id = 11; // unsaved model, set the key explicitly
        $event = new WorkspaceStatusChanged($workspace);

        $channels = $event->broadcastOn();
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertEquals('private-workspace.11', $channels[0]->name);
        $this->assertEquals('workspace.status_changed', $event->broadcastAs());
    }

    public function test_payment_status_changed_broadcasts_on_private_workspace_channel(): void
    {
        $payment = Payment::factory()->make(['workspace_id' => 21]);
        $event = new PaymentStatusChanged($payment);

        $channels = $event->broadcastOn();
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertEquals('private-workspace.21', $channels[0]->name);
        $this->assertEquals('payment.status_changed', $event->broadcastAs());
    }

    // --- ContractController call-sites -------------------------------------

    public function test_send_dispatches_contract_status_changed(): void
    {
        Event::fake([ContractStatusChanged::class]);
        [$manager, , $workspace] = $this->makeWorkspace();
        $contract = Contract::factory()->create(['workspace_id' => $workspace->id, 'created_by' => $manager->id, 'status' => 'draft']);

        $this->actingAs($manager)->postJson("/api/contracts/{$contract->id}/send")->assertOk();

        Event::assertDispatched(ContractStatusChanged::class, fn ($e) => $e->contract->id === $contract->id);
    }

    public function test_client_action_approved_dispatches_contract_status_changed(): void
    {
        Event::fake([ContractStatusChanged::class]);
        [$manager, $client, $workspace] = $this->makeWorkspace();
        $contract = Contract::factory()->create(['workspace_id' => $workspace->id, 'created_by' => $manager->id, 'status' => 'sent']);

        $token = $client->createToken('test')->plainTextToken;
        $this->app->make('auth')->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson("/api/contracts/{$contract->id}/client-action", ['action' => 'approved'])
            ->assertOk();

        Event::assertDispatched(ContractStatusChanged::class, fn ($e) => $e->contract->id === $contract->id && $e->contract->status === 'client_approved');
    }

    public function test_client_action_edit_requested_dispatches_contract_status_changed(): void
    {
        Event::fake([ContractStatusChanged::class]);
        [$manager, $client, $workspace] = $this->makeWorkspace();
        $contract = Contract::factory()->create(['workspace_id' => $workspace->id, 'created_by' => $manager->id, 'status' => 'sent']);

        $token = $client->createToken('test')->plainTextToken;
        $this->app->make('auth')->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson("/api/contracts/{$contract->id}/client-action", ['action' => 'edit_requested'])
            ->assertOk();

        Event::assertDispatched(ContractStatusChanged::class, fn ($e) => $e->contract->id === $contract->id && $e->contract->status === 'edit_requested');
    }

    public function test_company_approve_dispatches_contract_status_changed_and_activates_workspace_when_already_paid(): void
    {
        Event::fake([ContractStatusChanged::class, WorkspaceStatusChanged::class]);
        [$manager, $client, $workspace] = $this->makeWorkspace('inactive');
        $contract = Contract::factory()->create(['workspace_id' => $workspace->id, 'created_by' => $manager->id, 'status' => 'client_approved']);
        Payment::factory()->create(['workspace_id' => $workspace->id, 'client_id' => $client->id, 'status' => 'approved']);

        $this->actingAs($manager)->postJson("/api/contracts/{$contract->id}/company-approve")->assertOk();

        Event::assertDispatched(ContractStatusChanged::class, fn ($e) => $e->contract->id === $contract->id);
        Event::assertDispatched(WorkspaceStatusChanged::class, fn ($e) => $e->workspace->id === $workspace->id && $e->workspace->status === 'active');
    }

    public function test_company_approve_does_not_activate_workspace_without_an_approved_payment(): void
    {
        Event::fake([ContractStatusChanged::class, WorkspaceStatusChanged::class]);
        [$manager, , $workspace] = $this->makeWorkspace('inactive');
        $contract = Contract::factory()->create(['workspace_id' => $workspace->id, 'created_by' => $manager->id, 'status' => 'client_approved']);

        $this->actingAs($manager)->postJson("/api/contracts/{$contract->id}/company-approve")->assertOk();

        Event::assertDispatched(ContractStatusChanged::class);
        Event::assertNotDispatched(WorkspaceStatusChanged::class);
    }

    public function test_complete_dispatches_contract_status_changed_and_activates_workspace_when_already_paid(): void
    {
        Event::fake([ContractStatusChanged::class, WorkspaceStatusChanged::class]);
        [$manager, $client, $workspace] = $this->makeWorkspace('inactive');
        $contract = Contract::factory()->create(['workspace_id' => $workspace->id, 'created_by' => $manager->id, 'status' => 'company_approved']);
        Payment::factory()->create(['workspace_id' => $workspace->id, 'client_id' => $client->id, 'status' => 'approved']);

        $this->actingAs($manager)->postJson("/api/contracts/{$contract->id}/complete")->assertOk();

        Event::assertDispatched(ContractStatusChanged::class, fn ($e) => $e->contract->id === $contract->id);
        Event::assertDispatched(WorkspaceStatusChanged::class, fn ($e) => $e->workspace->id === $workspace->id);
    }

    // --- PaymentController call-sites ---------------------------------------

    public function test_store_dispatches_payment_status_changed(): void
    {
        Event::fake([PaymentStatusChanged::class]);
        [$manager, $client, $workspace] = $this->makeWorkspace();

        $token = $client->createToken('test')->plainTextToken;
        $this->app->make('auth')->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson("/api/workspaces/{$workspace->id}/payments", [
                'amount' => 1000,
                'method_type' => 'bank_transfer',
            ])
            ->assertStatus(201);

        Event::assertDispatched(PaymentStatusChanged::class, fn ($e) => $e->payment->workspace_id === $workspace->id && $e->payment->status === 'pending');
    }

    public function test_review_rejected_dispatches_only_payment_status_changed(): void
    {
        Event::fake([PaymentStatusChanged::class, ContractStatusChanged::class, WorkspaceStatusChanged::class]);
        [$manager, $client, $workspace] = $this->makeWorkspace();
        $payment = Payment::factory()->create(['workspace_id' => $workspace->id, 'client_id' => $client->id, 'status' => 'pending']);

        $this->actingAs($manager)->postJson("/api/payments/{$payment->id}/review", ['action' => 'rejected'])->assertOk();

        Event::assertDispatched(PaymentStatusChanged::class, fn ($e) => $e->payment->id === $payment->id && $e->payment->status === 'pending');
        Event::assertNotDispatched(ContractStatusChanged::class);
        Event::assertNotDispatched(WorkspaceStatusChanged::class);
    }

    public function test_review_approved_dispatches_payment_contract_and_workspace_events(): void
    {
        Event::fake([PaymentStatusChanged::class, ContractStatusChanged::class, WorkspaceStatusChanged::class]);
        [$manager, $client, $workspace] = $this->makeWorkspace('inactive');
        $contract = Contract::factory()->create(['workspace_id' => $workspace->id, 'created_by' => $manager->id, 'status' => 'client_approved']);
        $payment = Payment::factory()->create(['workspace_id' => $workspace->id, 'client_id' => $client->id, 'status' => 'pending']);

        $this->actingAs($manager)->postJson("/api/payments/{$payment->id}/review", ['action' => 'approved'])->assertOk();

        Event::assertDispatched(PaymentStatusChanged::class, fn ($e) => $e->payment->id === $payment->id && $e->payment->status === 'approved');
        // The contract cascades all the way to 'completed' within this single
        // request (see PaymentReviewActivationCharacterizationTest) — the
        // event fires once, carrying that final status.
        Event::assertDispatched(ContractStatusChanged::class, fn ($e) => $e->contract->id === $contract->id && $e->contract->status === 'completed');
        Event::assertDispatched(WorkspaceStatusChanged::class, fn ($e) => $e->workspace->id === $workspace->id && $e->workspace->status === 'active');
    }
}
