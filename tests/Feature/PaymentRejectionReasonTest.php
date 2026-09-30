<?php

namespace Tests\Feature;

use App\Events\ContractCompleted;
use App\Events\ContractStatusChanged;
use App\Events\PaymentReviewed;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Payment;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class PaymentRejectionReasonTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejection_stores_reason_in_rejection_reason_column_and_keeps_notes(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $client = Client::factory()->create(['manager_id' => $manager->id]);
        $workspace = Workspace::factory()->create([
            'client_id' => $client->id,
            'manager_id' => $manager->id,
            'status' => 'inactive',
        ]);

        $payment = Payment::create([
            'workspace_id' => $workspace->id,
            'client_id' => $client->id,
            'amount' => 5000,
            'currency' => 'SAR',
            'status' => 'pending',
            'method_type' => 'bank_transfer',
            'notes' => 'ملاحظات العميل الأصلية للدفعة',
        ]);

        $response = $this->actingAs($manager)->postJson("/api/payments/{$payment->id}/review", [
            'action' => 'rejected',
            'rejection_reason' => 'إيصال التحويل غير مكتمل الأرقام',
        ]);

        $response->assertOk();
        $fresh = $payment->fresh();
        $this->assertEquals('rejected', $fresh->status);
        $this->assertEquals('إيصال التحويل غير مكتمل الأرقام', $fresh->rejection_reason);
        $this->assertEquals('ملاحظات العميل الأصلية للدفعة', $fresh->notes);
    }

    public function test_approving_payment_clears_rejection_reason_and_preserves_existing_notes_if_not_overwritten(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $client = Client::factory()->create(['manager_id' => $manager->id]);
        $workspace = Workspace::factory()->create([
            'client_id' => $client->id,
            'manager_id' => $manager->id,
            'status' => 'inactive',
        ]);

        $payment = Payment::create([
            'workspace_id' => $workspace->id,
            'client_id' => $client->id,
            'amount' => 5000,
            'currency' => 'SAR',
            'status' => 'pending',
            'method_type' => 'bank_transfer',
            'notes' => 'ملاحظة مهمة',
            'rejection_reason' => 'سبب سابق',
        ]);

        $response = $this->actingAs($manager)->postJson("/api/payments/{$payment->id}/review", [
            'action' => 'approved',
        ]);

        $response->assertOk();
        $fresh = $payment->fresh();
        $this->assertEquals('approved', $fresh->status);
        $this->assertNull($fresh->rejection_reason);
        $this->assertEquals('ملاحظة مهمة', $fresh->notes);
    }

    public function test_activating_workspace_on_payment_approval_marks_company_approved_contracts_as_completed(): void
    {
        Event::fake([ContractCompleted::class, ContractStatusChanged::class]);

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
            'status' => 'company_approved',
            'contract_type' => 'main',
        ]);

        $payment = Payment::create([
            'workspace_id' => $workspace->id,
            'client_id' => $client->id,
            'amount' => 5000,
            'currency' => 'SAR',
            'status' => 'pending',
            'method_type' => 'bank_transfer',
        ]);

        $response = $this->actingAs($manager)->postJson("/api/payments/{$payment->id}/review", [
            'action' => 'approved',
        ]);

        $response->assertOk();
        $this->assertEquals('active', $workspace->fresh()->status);
        $this->assertEquals('completed', $contract->fresh()->status);

        Event::assertDispatched(ContractCompleted::class);
        Event::assertDispatched(ContractStatusChanged::class);
    }

    public function test_company_approving_contract_when_payment_is_already_approved_completes_contract_and_activates_workspace(): void
    {
        Event::fake([ContractCompleted::class, ContractStatusChanged::class]);

        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN, 'signature_data' => 'Admin Sig']);
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
            'contract_type' => 'main',
        ]);

        Payment::create([
            'workspace_id' => $workspace->id,
            'client_id' => $client->id,
            'amount' => 5000,
            'currency' => 'SAR',
            'status' => 'approved',
            'method_type' => 'bank_transfer',
        ]);

        $response = $this->actingAs($admin)->postJson("/api/contracts/{$contract->id}/company-approve", [
            'use_saved_signature' => true,
        ]);

        $response->assertOk();
        $this->assertEquals('active', $workspace->fresh()->status);
        $this->assertEquals('completed', $contract->fresh()->status);

        Event::assertDispatched(ContractCompleted::class);
        Event::assertDispatched(ContractStatusChanged::class);
    }
}
