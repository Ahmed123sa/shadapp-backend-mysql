<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Payment;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\PaymentOverdueStaffNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A payment is reviewed on its proof, so the proof is mandatory:
 * - creating a payment needs at least one proof file;
 * - sending a scheduled installment or a rejected payment back for review
 *   needs a NEW proof (a rejected file must not come back as is);
 * - editing a payment that is already pending keeps its proof and needs none.
 */
class PaymentProofRequiredTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;
    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $this->client = Client::factory()->create(['manager_id' => $manager->id]);
        $this->workspace = Workspace::factory()->create([
            'client_id' => $this->client->id,
            'manager_id' => $manager->id,
            'status' => 'active',
        ]);
        Contract::create([
            'workspace_id' => $this->workspace->id,
            'title' => 'Main',
            'status' => 'company_approved',
            'value' => 10000,
            'currency' => 'SAR',
        ]);
    }

    private function asClient(): self
    {
        $this->app->make('auth')->forgetGuards();
        $this->defaultHeaders = [];

        return $this->withHeaders(['Authorization' => 'Bearer ' . $this->client->createToken('t')->plainTextToken]);
    }

    private function proof(string $name = 'proof.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 100, 'application/pdf');
    }

    private function payment(string $status, array $extra = []): Payment
    {
        return Payment::create(array_merge([
            'workspace_id' => $this->workspace->id,
            'client_id' => $this->client->id,
            'amount' => 1000,
            'currency' => 'SAR',
            'status' => $status,
            'method_type' => 'bank_transfer',
        ], $extra));
    }

    private function url(?Payment $p = null): string
    {
        return "/api/workspaces/{$this->workspace->id}/payments" . ($p ? "/{$p->id}" : '');
    }

    public function test_creating_a_payment_without_a_proof_is_refused_and_nothing_is_stored(): void
    {
        $this->asClient()
            ->postJson($this->url(), ['amount' => 500, 'currency' => 'SAR', 'method_type' => 'bank_transfer'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('proof_files');

        $this->assertSame(0, Payment::count());
    }

    public function test_creating_a_payment_with_an_empty_proof_list_is_refused(): void
    {
        $this->asClient()
            ->postJson($this->url(), ['amount' => 500, 'currency' => 'SAR', 'method_type' => 'bank_transfer', 'proof_files' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('proof_files');
    }

    public function test_creating_a_payment_with_a_proof_works(): void
    {
        $this->asClient()
            ->postJson($this->url(), ['amount' => 500, 'currency' => 'SAR', 'method_type' => 'bank_transfer', 'proof_files' => [$this->proof()]])
            ->assertStatus(201);

        $this->assertNotEmpty(Payment::first()->proof_file_url);
    }

    public function test_a_rejected_payment_cannot_go_back_for_review_without_a_new_proof(): void
    {
        $payment = $this->payment('rejected', ['proof_file_url' => ['/storage/old.pdf'], 'rejection_reason' => 'blurry']);

        $this->asClient()
            ->putJson($this->url($payment), ['amount' => 1200])
            ->assertStatus(422)
            ->assertJsonValidationErrors('proof_files');

        $fresh = $payment->fresh();
        $this->assertSame('rejected', $fresh->status);
        $this->assertSame('blurry', $fresh->rejection_reason);
    }

    public function test_a_rejected_payment_goes_back_for_review_with_a_new_proof(): void
    {
        $payment = $this->payment('rejected', ['proof_file_url' => ['/storage/old.pdf'], 'rejection_reason' => 'blurry']);

        $this->asClient()
            ->putJson($this->url($payment), ['proof_files' => [$this->proof('new.pdf')]])
            ->assertOk();

        $fresh = $payment->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertNull($fresh->rejection_reason);
        $this->assertNotSame(['/storage/old.pdf'], $fresh->proof_file_url);
    }

    public function test_a_scheduled_installment_cannot_become_pending_without_a_proof(): void
    {
        $payment = $this->payment('scheduled', ['due_date' => now()->addDays(5)]);

        $this->asClient()
            ->putJson($this->url($payment), ['method_type' => 'instapay'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('proof_files');

        $this->assertSame('scheduled', $payment->fresh()->status);
    }

    public function test_editing_a_pending_payment_keeps_its_proof_and_needs_no_new_file(): void
    {
        $payment = $this->payment('pending', ['proof_file_url' => ['/storage/keep.pdf']]);

        $this->asClient()
            ->putJson($this->url($payment), ['amount' => 1500])
            ->assertOk();

        $fresh = $payment->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertEquals(1500, $fresh->amount);
        // The accessor returns a signed URL, so check the stored value itself.
        $this->assertStringContainsString('keep.pdf', (string) $fresh->getRawOriginal('proof_file_url'));
    }

    public function test_an_overdue_installment_can_be_paid_with_a_new_proof_and_reviewers_are_told(): void
    {
        \Illuminate\Support\Facades\Notification::fake();

        $accountant = User::factory()->create(['role' => User::ROLE_ACCOUNTANT]);
        $payment = $this->payment('overdue', ['due_date' => now()->subDays(3)]);

        $this->asClient()
            ->putJson($this->url($payment), ['method_type' => 'instapay', 'proof_files' => [$this->proof('late.pdf')]])
            ->assertOk();

        $this->assertSame('pending', $payment->fresh()->status);
        \Illuminate\Support\Facades\Notification::assertSentTo($accountant, \App\Notifications\PaymentCreatedNotification::class);
    }

    public function test_an_overdue_installment_cannot_become_pending_without_a_proof(): void
    {
        $payment = $this->payment('overdue', ['due_date' => now()->subDays(3)]);

        $this->asClient()
            ->putJson($this->url($payment), ['method_type' => 'instapay'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('proof_files');

        $this->assertSame('overdue', $payment->fresh()->status);
    }

    public function test_an_approved_payment_still_cannot_be_edited(): void
    {
        $payment = $this->payment('approved', ['proof_file_url' => ['/storage/a.pdf']]);

        $this->asClient()
            ->putJson($this->url($payment), ['amount' => 1, 'proof_files' => [$this->proof()]])
            ->assertStatus(422);

        $this->assertSame('approved', $payment->fresh()->status);
    }

    public function test_accountants_are_told_once_when_an_installment_becomes_overdue(): void
    {
        Notification::fake();

        $accountant = User::factory()->create(['role' => User::ROLE_ACCOUNTANT]);
        $inactive = User::factory()->create(['role' => User::ROLE_ACCOUNTANT, 'is_active' => false]);
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);

        $payment = $this->payment('scheduled', ['due_date' => now()->subDays(2)]);

        $this->artisan('payments:send-reminders')->assertExitCode(0);

        $this->assertSame('overdue', $payment->fresh()->status);
        Notification::assertSentTo($accountant, PaymentOverdueStaffNotification::class);
        Notification::assertNotSentTo($inactive, PaymentOverdueStaffNotification::class);
        Notification::assertNotSentTo($manager, PaymentOverdueStaffNotification::class);

        // Already overdue: the next run does not tell them again.
        $this->artisan('payments:send-reminders')->assertExitCode(0);
        Notification::assertSentToTimes($accountant, PaymentOverdueStaffNotification::class, 1);
    }
}
