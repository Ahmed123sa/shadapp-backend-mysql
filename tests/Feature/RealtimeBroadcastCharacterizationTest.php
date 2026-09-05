<?php

namespace Tests\Feature;

use App\Domains\Chat\MessageSent;
use App\Events\ContractClientApproved;
use App\Events\ContractCompanyApproved;
use App\Events\ContractCompleted;
use App\Events\ContractSent;
use App\Events\MessageUpdated;
use App\Events\PaymentCreated;
use App\Events\PaymentReviewed;
use App\Events\PaymentScheduleChanged;
use App\Models\ChatMessage;
use App\Models\Payment;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Characterization test — Stage 0 of REALTIME_PLAN.md.
 *
 * Locks in the CURRENT broadcasting surface (as measured in section 1.1 of
 * the plan) before Stage 1 adds ContractStatusChanged / WorkspaceStatusChanged
 * / PaymentStatusChanged. Only 3 of the 12 domain events actually implement
 * ShouldBroadcast today; the rest fire notifications/emails only. If this
 * test starts failing, it's a signal an existing broadcast contract changed
 * — the "does NOT broadcast yet" assertions are expected to flip to true one
 * by one as Stage 1 lands, and should be updated deliberately then, not
 * silently by deleting the assertion.
 *
 * Mirrored from shadapp-backend (the Postgres copy) — this is the MySQL
 * copy kept in sync per the project's established dual-backend convention.
 */
class RealtimeBroadcastCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_three_events_currently_implement_should_broadcast(): void
    {
        $this->assertContains(ShouldBroadcast::class, class_implements(MessageSent::class));
        $this->assertContains(ShouldBroadcast::class, class_implements(MessageUpdated::class));
        $this->assertContains(ShouldBroadcast::class, class_implements(PaymentScheduleChanged::class));
    }

    public function test_contract_lifecycle_events_do_not_broadcast_yet(): void
    {
        $this->assertNotContains(ShouldBroadcast::class, class_implements(ContractSent::class) ?: []);
        $this->assertNotContains(ShouldBroadcast::class, class_implements(ContractClientApproved::class) ?: []);
        $this->assertNotContains(ShouldBroadcast::class, class_implements(ContractCompanyApproved::class) ?: []);
        $this->assertNotContains(ShouldBroadcast::class, class_implements(ContractCompleted::class) ?: []);
    }

    public function test_payment_lifecycle_events_do_not_broadcast_yet(): void
    {
        $this->assertNotContains(ShouldBroadcast::class, class_implements(PaymentCreated::class) ?: []);
        $this->assertNotContains(ShouldBroadcast::class, class_implements(PaymentReviewed::class) ?: []);
    }

    public function test_message_sent_broadcasts_on_private_workspace_channel(): void
    {
        // No ChatMessageFactory exists in this codebase — instantiate the
        // model directly (unsaved) rather than adding one just for this
        // characterization test.
        $message = new ChatMessage(['workspace_id' => 42]);
        $event = new MessageSent($message);

        $channels = $event->broadcastOn();
        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertEquals('private-workspace.42', $channels[0]->name);
        $this->assertEquals('message.sent', $event->broadcastAs());
    }

    public function test_message_updated_broadcasts_on_private_workspace_channel(): void
    {
        $message = new ChatMessage(['workspace_id' => 7]);
        $event = new MessageUpdated($message);

        $channels = $event->broadcastOn();
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertEquals('private-workspace.7', $channels[0]->name);
        $this->assertEquals('message.updated', $event->broadcastAs());
    }

    public function test_payment_schedule_changed_broadcasts_on_private_workspace_channel(): void
    {
        $payment = Payment::factory()->make(['workspace_id' => 99]);
        $event = new PaymentScheduleChanged($payment, 'updated');

        $channels = $event->broadcastOn();
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertEquals('private-workspace.99', $channels[0]->name);
        $this->assertEquals('payment.schedule.changed', $event->broadcastAs());
    }
}
