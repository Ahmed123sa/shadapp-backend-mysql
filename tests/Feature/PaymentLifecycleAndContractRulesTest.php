<?php

namespace Tests\Feature;

use App\Events\PaymentCreated;
use App\Events\PaymentReviewed;
use App\Events\PaymentStatusChanged;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Payment;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\PaymentCreatedNotification;
use App\Notifications\PaymentReviewedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentLifecycleAndContractRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function resetAuth(): void
    {
        $this->app->make('auth')->forgetGuards();
    }

    private function createWorkspaceWithManager(): array
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN, 'signature_data' => 'Admin Sig']);
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $client = Client::factory()->create(['manager_id' => $manager->id]);
        $workspace = Workspace::factory()->create([
            'client_id' => $client->id,
            'manager_id' => $manager->id,
            'status' => 'active',
            'activated_at' => '2026-01-01 10:00:00',
        ]);

        return [$admin, $manager, $client, $workspace];
    }

    private function actingAsClient(Client $client): self
    {
        $token = $client->createToken('test')->plainTextToken;
        $this->resetAuth();
        $this->defaultHeaders = [];
        return $this->withHeaders(['Authorization' => 'Bearer ' . $token]);
    }

    public function test_uploading_proof_for_scheduled_payment_turns_it_pending_and_notifies_manager_and_super_admin(): void
    {
        Notification::fake();
        Event::fake([PaymentCreated::class, PaymentStatusChanged::class]);

        [$admin, $manager, $client, $workspace] = $this->createWorkspaceWithManager();

        $payment = Payment::create([
            'workspace_id' => $workspace->id,
            'client_id' => $client->id,
            'amount' => 5000,
            'currency' => 'SAR',
            'status' => 'scheduled',
            'method_type' => 'bank_transfer',
            'due_date' => now()->addDays(5),
        ]);

        $file = UploadedFile::fake()->create('proof.pdf', 500, 'application/pdf');

        $response = $this->actingAsClient($client)
            ->putJson("/api/workspaces/{$workspace->id}/payments/{$payment->id}", [
                'proof_files' => [$file],
            ]);

        $response->assertOk();
        $this->assertEquals('pending', $payment->fresh()->status);

        Event::assertDispatched(PaymentCreated::class);
        Event::assertDispatched(PaymentStatusChanged::class);
    }

    public function test_rejecting_a_payment_sets_status_to_rejected_and_stores_rejection_reason(): void
    {
        Notification::fake();

        [$admin, $manager, $client, $workspace] = $this->createWorkspaceWithManager();

        $payment = Payment::create([
            'workspace_id' => $workspace->id,
            'client_id' => $client->id,
            'amount' => 3000,
            'currency' => 'SAR',
            'status' => 'pending',
            'method_type' => 'bank_transfer',
        ]);

        Sanctum::actingAs($manager);

        $response = $this->postJson("/api/payments/{$payment->id}/review", [
            'action' => 'rejected',
            'notes' => 'صورة التحويل غير واضحة، يرجى رفع صورة واضحة ومختومة',
        ]);

        $response->assertOk();
        $fresh = $payment->fresh();
        $this->assertEquals('rejected', $fresh->status);
        $this->assertEquals('صورة التحويل غير واضحة، يرجى رفع صورة واضحة ومختومة', $fresh->notes);

        Notification::assertSentTo($client, PaymentReviewedNotification::class, function ($notif) {
            return $notif->action === 'rejected' && $notif->workspaceActivated === false;
        });
    }

    public function test_client_can_reupload_proof_for_rejected_payment_which_resets_to_pending(): void
    {
        Event::fake([PaymentCreated::class, PaymentStatusChanged::class]);

        [$admin, $manager, $client, $workspace] = $this->createWorkspaceWithManager();

        $payment = Payment::create([
            'workspace_id' => $workspace->id,
            'client_id' => $client->id,
            'amount' => 3000,
            'currency' => 'SAR',
            'status' => 'rejected',
            'method_type' => 'bank_transfer',
            'notes' => 'صورة التحويل غير واضحة',
        ]);

        $file = UploadedFile::fake()->create('new_proof.pdf', 500, 'application/pdf');

        $response = $this->actingAsClient($client)
            ->putJson("/api/workspaces/{$workspace->id}/payments/{$payment->id}", [
                'proof_files' => [$file],
            ]);

        $response->assertOk();
        $fresh = $payment->fresh();
        $this->assertEquals('pending', $fresh->status);
        $this->assertNull($fresh->notes);

        Event::assertDispatched(PaymentCreated::class);
    }

    public function test_approving_payment_in_active_workspace_does_not_change_activated_at_or_auto_sign_additional_contracts(): void
    {
        [$admin, $manager, $client, $workspace] = $this->createWorkspaceWithManager();

        $additionalContract = Contract::create([
            'workspace_id' => $workspace->id,
            'title' => 'Extra SEO Service',
            'contract_type' => 'additional',
            'status' => 'client_approved',
            'value' => 2000,
            'currency' => 'SAR',
        ]);

        $payment = Payment::create([
            'workspace_id' => $workspace->id,
            'client_id' => $client->id,
            'amount' => 1000,
            'currency' => 'SAR',
            'status' => 'pending',
            'method_type' => 'bank_transfer',
        ]);

        Sanctum::actingAs($manager);

        $response = $this->postJson("/api/payments/{$payment->id}/review", [
            'action' => 'approved',
        ]);

        $response->assertOk();
        $this->assertEquals('approved', $payment->fresh()->status);

        // Workspace activated_at should remain unchanged
        $this->assertEquals('2026-01-01 10:00:00', $workspace->fresh()->activated_at->format('Y-m-d H:i:s'));

        // Additional contract should NOT be company_approved or completed automatically by unrelated payment
        $this->assertEquals('client_approved', $additionalContract->fresh()->status);
        $this->assertNull($additionalContract->fresh()->company_signed_at);
    }

    public function test_super_admin_company_approve_on_additional_contract_completes_it(): void
    {
        [$admin, $manager, $client, $workspace] = $this->createWorkspaceWithManager();

        $additionalContract = Contract::create([
            'workspace_id' => $workspace->id,
            'title' => 'Extra SEO Service',
            'contract_type' => 'additional',
            'status' => 'client_approved',
            'value' => 2000,
            'currency' => 'SAR',
        ]);

        // Manager cannot company-approve
        Sanctum::actingAs($manager);
        $response = $this->postJson("/api/contracts/{$additionalContract->id}/company-approve", [
            'use_saved_signature' => true,
        ]);
        $response->assertForbidden();

        // Super Admin can company-approve with saved signature and it completes immediately
        Sanctum::actingAs($admin);
        $response = $this->postJson("/api/contracts/{$additionalContract->id}/company-approve", [
            'use_saved_signature' => true,
        ]);
        $response->assertOk();

        $freshContract = $additionalContract->fresh();
        $this->assertEquals('completed', $freshContract->status);
        $this->assertEquals('Admin Sig', $freshContract->company_signature_data);
    }

    public function test_company_approve_fails_if_no_signature_is_provided_or_saved(): void
    {
        $adminNoSig = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN, 'signature_data' => null]);
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $client = Client::factory()->create(['manager_id' => $manager->id]);
        $workspace = Workspace::factory()->create([
            'client_id' => $client->id,
            'manager_id' => $manager->id,
            'status' => 'active',
        ]);
        $contract = Contract::create([
            'workspace_id' => $workspace->id,
            'title' => 'Contract',
            'status' => 'client_approved',
            'value' => 2000,
            'currency' => 'SAR',
        ]);

        Sanctum::actingAs($adminNoSig);
        $response = $this->postJson("/api/contracts/{$contract->id}/company-approve", []);

        $response->assertStatus(422);
        $response->assertJson(['code' => 'signature_required']);
    }
}
