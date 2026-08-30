<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Client;
use App\Models\Workspace;
use App\Models\Contract;
use App\Models\Payment;
use App\Notifications\PaymentCreatedNotification;
use App\Notifications\PaymentReviewedNotification;
use App\Notifications\PaymentReminderNotification;
use App\Notifications\PaymentScheduledNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Phase4FixReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private User $manager;
    private Client $client;
    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $this->manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER, 'super_admin_id' => $this->superAdmin->id]);
        $this->client = Client::factory()->create(['manager_id' => $this->manager->id, 'client_type' => 'business']);
        $this->workspace = Workspace::factory()->create([
            'client_id' => $this->client->id,
            'manager_id' => $this->manager->id,
        ]);
    }

    public function test_payment_notifications_use_dynamic_currency(): void
    {
        $payment = Payment::factory()->create([
            'workspace_id' => $this->workspace->id,
            'client_id' => $this->client->id,
            'amount' => '1234.50',
            'currency' => 'USD',
            'status' => 'pending',
        ]);

        // Reviewed
        $db = (new PaymentReviewedNotification($payment, 'approved'))->toDatabase($this->client);
        $fcm = (new PaymentReviewedNotification($payment, 'approved'))->toFcm($this->client);
        $this->assertStringContainsString('USD', $db['message']);
        $this->assertStringNotContainsString('ر.س', $db['message']);
        $this->assertStringNotContainsString('ريال', $db['message']);
        $this->assertStringContainsString('USD', $fcm['body']);
        $this->assertStringNotContainsString('ر.س', $fcm['body']);

        // Scheduled
        $dbScheduled = (new PaymentScheduledNotification($payment))->toDatabase($this->client);
        $fcmScheduled = (new PaymentScheduledNotification($payment))->toFcm($this->client);
        $this->assertStringContainsString('USD', $dbScheduled['body']);
        $this->assertStringNotContainsString('ريال', $dbScheduled['body']);
        $this->assertStringContainsString('USD', $fcmScheduled['body']);
        $this->assertStringNotContainsString('ريال', $fcmScheduled['body']);

        // Reminder
        $dbReminder = (new PaymentReminderNotification($payment, 'today'))->toDatabase($this->client);
        $fcmReminder = (new PaymentReminderNotification($payment, 'today'))->toFcm($this->client);
        $this->assertStringContainsString('USD', $dbReminder['body']);
        $this->assertStringNotContainsString('ريال', $dbReminder['body']);
        $this->assertStringContainsString('USD', $fcmReminder['body']);
        $this->assertStringNotContainsString('ريال', $fcmReminder['body']);
    }

    public function test_payment_created_notification_includes_client_name_and_workspace_keys(): void
    {
        $this->client->update(['company_name' => 'شركة النور للهندسة']);
        $payment = Payment::factory()->create([
            'workspace_id' => $this->workspace->id,
            'client_id' => $this->client->id,
            'amount' => '1234.50',
            'currency' => 'USD',
            'status' => 'pending',
        ]);

        $db = (new PaymentCreatedNotification($payment))->toDatabase($this->manager);
        $fcm = (new PaymentCreatedNotification($payment))->toFcm($this->manager);

        $this->assertStringContainsString('شركة النور للهندسة', $db['message']);
        $this->assertStringContainsString('1234.50 USD', $db['message']);
        $this->assertEquals($this->workspace->id, $db['workspace_id']);
        $this->assertEquals($this->client->id, $db['client_id']);
        $this->assertStringContainsString('شركة النور للهندسة', $fcm['body']);
        $this->assertEquals((string) $this->workspace->id, $fcm['data']['workspace_id']);
        $this->assertEquals((string) $this->client->id, $fcm['data']['client_id']);
    }

    public function test_payment_reviewed_notification_includes_workspace_keys(): void
    {
        $payment = Payment::factory()->create([
            'workspace_id' => $this->workspace->id,
            'client_id' => $this->client->id,
            'amount' => '1234.50',
            'currency' => 'USD',
            'status' => 'approved',
        ]);

        $dbApproved = (new PaymentReviewedNotification($payment, 'approved'))->toDatabase($this->client);
        $fcmRejected = (new PaymentReviewedNotification($payment, 'rejected'))->toFcm($this->client);

        $this->assertArrayHasKey('workspace_id', $dbApproved);
        $this->assertArrayHasKey('client_id', $dbApproved);
        $this->assertEquals($this->workspace->id, $dbApproved['workspace_id']);
        $this->assertEquals($this->client->id, $dbApproved['client_id']);
        $this->assertStringContainsString('تم اعتمادها', $dbApproved['message']);

        $dbRejected = (new PaymentReviewedNotification($payment, 'rejected'))->toDatabase($this->client);
        $this->assertStringContainsString('تم رفضها', $dbRejected['message']);
        $this->assertStringContainsString('مرفوضة', $fcmRejected['body']);
    }

    public function test_contract_index_regenerates_missing_pdf(): void
    {
        Storage::fake('public');

        $contract = Contract::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->manager->id,
            'status' => 'sent',
            'value' => 5000,
            'currency' => 'USD',
            'start_date' => null,
            'end_date' => null,
        ]);

        $expectedFile = 'contracts/contract-' . $contract->id . '-signed.pdf';
        $contract->update(['pdf_url' => Storage::url($expectedFile)]);

        // The file does not exist yet on the (fake) disk.
        Storage::disk('public')->assertMissing($expectedFile);

        $response = $this->actingAs($this->manager)->getJson("/api/workspaces/{$this->workspace->id}/contracts");
        $response->assertOk();

        // Contract::index() guarantees a fresh PDF exists for each contract.
        Storage::disk('public')->assertExists($expectedFile);

        $payments = $response->json('contracts.data');
        $this->assertNotEmpty($payments);
        $this->assertStringContainsString($expectedFile, $payments[0]['pdf_url']);
    }
}