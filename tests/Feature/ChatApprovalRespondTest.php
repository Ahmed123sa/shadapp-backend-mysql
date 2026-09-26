<?php

namespace Tests\Feature;

use App\Events\MessageUpdated;
use App\Mail\ApprovalCertificateMail;
use App\Models\Approval;
use App\Models\ChatMessage;
use App\Models\Client;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\ApprovalRespondedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 23 Sept 2026 — a client answers an approval through
 * POST /chat/{chatMessage}/respond. That route used to notify the requester
 * directly and nothing else: the certificate email never went out, a
 * deactivated requester was still notified, and the updated card was never
 * broadcast. It now goes through the ApprovalResponded event and broadcasts
 * MessageUpdated.
 */
class ChatApprovalRespondTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private Client $client;
    private ChatMessage $message;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Mail::fake();
        Notification::fake();

        $this->manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $this->client = Client::factory()->create(['manager_id' => $this->manager->id, 'signature_data' => 'توقيع تجريبي']);
        $workspace = Workspace::factory()->create([
            'client_id' => $this->client->id,
            'manager_id' => $this->manager->id,
        ]);

        $this->actingAs($this->manager)
            ->postJson("/api/workspaces/{$workspace->id}/approvals", ['title' => 'Logo sign-off'])
            ->assertStatus(201);
        $this->message = ChatMessage::whereNotNull('approval_id')->firstOrFail();
        Notification::fake(); // ignore the request's own notifications
    }

    private function respond(string $action): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->client, 'client')
            ->postJson("/api/chat/{$this->message->id}/respond", ['action' => $action])
            ->assertOk();
    }

    public function test_approving_notifies_the_manager_with_the_workspace(): void
    {
        $this->respond('approved');

        Notification::assertSentTo($this->manager, ApprovalRespondedNotification::class,
            fn ($n) => $n->toDatabase($this->manager)['workspace_id'] == $this->message->workspace_id);
    }

    public function test_approving_emails_the_certificate_to_the_manager_and_the_client(): void
    {
        $this->respond('approved');

        Mail::assertQueued(ApprovalCertificateMail::class, fn ($m) => $m->hasTo($this->manager->email));
        Mail::assertQueued(ApprovalCertificateMail::class, fn ($m) => $m->hasTo($this->client->email));
    }

    public function test_a_deactivated_manager_is_not_notified(): void
    {
        $this->manager->forceFill(['is_active' => false])->save();

        $this->respond('approved');

        Notification::assertNotSentTo($this->manager, ApprovalRespondedNotification::class);
        $this->assertSame('approved', Approval::find($this->message->approval_id)->status);
    }

    public function test_the_broadcast_card_carries_the_certificate(): void
    {
        Event::fake([MessageUpdated::class]);

        $this->respond('approved');

        Event::assertDispatched(MessageUpdated::class,
            fn (MessageUpdated $e) => $e->message->approval?->certificate?->pdf_url !== null);
    }

    public function test_the_updated_card_is_broadcast_live(): void
    {
        Event::fake([MessageUpdated::class]);

        $this->respond('edit_requested');

        Event::assertDispatched(MessageUpdated::class,
            fn (MessageUpdated $e) => $e->message->id === $this->message->id && $e->message->action_result === 'edit_requested');
    }

    // client-signature-plan.md ن5 — same rule as ContractController::clientAction():
    // approving with no saved signature used to succeed silently, leaving the
    // approval's signature (and its PDF certificate) blank.
    public function test_approving_without_a_saved_signature_is_rejected(): void
    {
        $this->client->update(['signature_data' => null]);

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->client, 'client')
            ->postJson("/api/chat/{$this->message->id}/respond", ['action' => 'approved'])
            ->assertStatus(422)
            ->assertJson(['code' => 'signature_required']);

        $this->assertSame('pending', Approval::find($this->message->approval_id)->status);
    }

    // edit_requested never carried a signature onto the approval and
    // shouldn't require one — only 'approved' does.
    public function test_requesting_edits_without_a_saved_signature_still_works(): void
    {
        $this->client->update(['signature_data' => null]);

        $this->respond('edit_requested');

        $this->assertSame('edit_requested', Approval::find($this->message->approval_id)->status);
    }

    // client-signature-plan.md ن5 — this endpoint is also reachable by staff
    // proxying the client's response (e.g. the AM entering it after verbal
    // sign-off over the phone), the same pattern already established and
    // tested for ContractController::clientAction() (see
    // RealWorldScenarioTest, which calls that endpoint as the AM, not the
    // client). Staff's own saved signature is irrelevant here — the one that
    // belongs on the approval is the client's own.
    public function test_a_manager_can_approve_on_the_clients_behalf_when_the_client_has_a_saved_signature(): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->manager)
            ->postJson("/api/chat/{$this->message->id}/respond", ['action' => 'approved'])
            ->assertOk();

        $this->assertSame('approved', Approval::find($this->message->approval_id)->status);
    }

    public function test_a_manager_cannot_approve_on_the_clients_behalf_without_the_clients_saved_signature(): void
    {
        $this->client->update(['signature_data' => null]);

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->manager)
            ->postJson("/api/chat/{$this->message->id}/respond", ['action' => 'approved'])
            ->assertStatus(422)
            ->assertJson(['code' => 'signature_required']);

        $this->assertSame('pending', Approval::find($this->message->approval_id)->status);
    }
}
