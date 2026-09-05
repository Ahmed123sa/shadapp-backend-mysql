<?php

namespace Tests\Feature;

use App\Events\ContractCompanyApproved;
use App\Events\PaymentReviewed;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Payment;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Characterization test — Stage 0 of REALTIME_PLAN.md.
 *
 * Locks in PaymentController@review's exact current side effects before
 * Stage 1 adds explicit broadcast dispatch calls inside this method (it must
 * be explicit — see the warning in plan section 2 — because the bulk
 * `->update()` on 'company_approved' contracts bypasses Eloquent model
 * events, so any Observer-based broadcast would silently never fire for
 * that half of the transition).
 *
 * The subtle behavior pinned down here: the method first promotes
 * 'client_approved' contracts to 'company_approved' one at a time (firing
 * ContractCompanyApproved per contract), then immediately runs a second bulk
 * query for 'company_approved' contracts -> 'completed'. Because the first
 * step already committed its writes, that second query also catches the
 * contract(s) the first step just promoted — so in a single review() call, a
 * contract that started as 'client_approved' ends the request as
 * 'completed', not 'company_approved', and only fires ContractCompanyApproved
 * once (never a "completed" event, since ContractCompleted is only
 * dispatched from ContractController@complete, not from here).
 *
 * Mirrored from shadapp-backend (the Postgres copy) — this is the MySQL
 * copy kept in sync per the project's established dual-backend convention.
 */
class PaymentReviewActivationCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_approving_payment_activates_workspace_and_cascades_contract_statuses(): void
    {
        Event::fake([ContractCompanyApproved::class, PaymentReviewed::class]);

        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $client = Client::factory()->create(['manager_id' => $manager->id, 'payment_status' => 'pending']);
        $workspace = Workspace::factory()->create([
            'client_id' => $client->id,
            'manager_id' => $manager->id,
            'status' => 'inactive',
            'activated_at' => null,
        ]);

        // One contract mid-flow (client signed, company hasn't yet) and one
        // the company already approved in an earlier request.
        $justApprovedByClient = Contract::factory()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $manager->id,
            'status' => 'client_approved',
        ]);
        $alreadyCompanyApproved = Contract::factory()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $manager->id,
            'status' => 'company_approved',
        ]);

        $payment = Payment::factory()->create([
            'workspace_id' => $workspace->id,
            'client_id' => $client->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($manager)->postJson("/api/payments/{$payment->id}/review", [
            'action' => 'approved',
        ]);
        $response->assertOk();

        // Both contracts end up 'completed' in the same request, per the
        // cascade described above.
        $this->assertEquals('completed', $justApprovedByClient->fresh()->status);
        $this->assertEquals('completed', $alreadyCompanyApproved->fresh()->status);

        // Only the contract that actually transitioned through the
        // individual ->update() loop fires the event; the bulk-updated one
        // does not (it never passes through an Eloquent model event at all).
        Event::assertDispatched(ContractCompanyApproved::class, 1);

        $workspace->refresh();
        $this->assertEquals('active', $workspace->status);
        $this->assertNotNull($workspace->activated_at);

        $this->assertEquals('approved', $payment->fresh()->status);
        $this->assertEquals('approved', $client->fresh()->payment_status);

        Event::assertDispatched(PaymentReviewed::class, 1);
    }

    public function test_rejecting_payment_does_not_touch_contracts_or_workspace(): void
    {
        Event::fake([ContractCompanyApproved::class, PaymentReviewed::class]);

        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $client = Client::factory()->create(['manager_id' => $manager->id]);
        $workspace = Workspace::factory()->create([
            'client_id' => $client->id,
            'manager_id' => $manager->id,
            'status' => 'inactive',
        ]);
        $contract = Contract::factory()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $manager->id,
            'status' => 'client_approved',
        ]);
        $payment = Payment::factory()->create([
            'workspace_id' => $workspace->id,
            'client_id' => $client->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($manager)->postJson("/api/payments/{$payment->id}/review", [
            'action' => 'rejected',
        ]);
        $response->assertOk();

        $this->assertEquals('client_approved', $contract->fresh()->status);
        $this->assertEquals('inactive', $workspace->fresh()->status);
        $this->assertEquals('pending', $payment->fresh()->status);

        Event::assertNotDispatched(ContractCompanyApproved::class);
        Event::assertDispatched(PaymentReviewed::class, 1);
    }
}
