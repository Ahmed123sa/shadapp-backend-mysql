<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\FileEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\ApprovalRequestedNotification;
use App\Notifications\ChatMessageSentNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * An approval request carries its files, wherever it was raised: from the
 * Approvals tab or from a chat message marked as needing approval. Before, a
 * file sent in chat with "needs approval" left the approval without it, the
 * client saw an empty card, and the notification read as an ordinary message.
 */
class ApprovalFilesTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private Client $client;
    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Notification::fake();

        $this->manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $this->client = Client::factory()->create(['manager_id' => $this->manager->id]);
        $this->workspace = Workspace::factory()->create(['client_id' => $this->client->id, 'manager_id' => $this->manager->id]);
        Sanctum::actingAs($this->manager);
    }

    private function chat(array $fields): \Illuminate\Testing\TestResponse
    {
        return $this->post("/api/workspaces/{$this->workspace->id}/chat", $fields, ['Accept' => 'application/json']);
    }

    private function image(string $name = '1.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name);
    }

    public function test_a_file_sent_for_approval_is_attached_to_the_approval(): void
    {
        $res = $this->chat(['file' => $this->image(), 'requires_action' => true])->assertCreated();

        $approvalId = $res->json('message.approval_id');
        $this->assertNotNull($approvalId);
        $file = FileEntry::where('workspace_id', $this->workspace->id)->firstOrFail();
        $this->assertSame($approvalId, $file->approval_id);
        $this->assertSame('approved', $file->status);
        $this->assertSame('1.jpg', $res->json('message.approval.files.0.name'));
    }

    public function test_the_title_is_the_file_name_when_there_is_no_text(): void
    {
        $res = $this->chat(['file' => $this->image('contract-draft.jpg'), 'requires_action' => true])->assertCreated();

        $this->assertSame('contract-draft.jpg', $res->json('message.approval.title'));
    }

    public function test_the_title_is_the_text_as_typed_without_a_fixed_prefix(): void
    {
        $res = $this->chat(['message' => 'Please approve the logo', 'requires_action' => true])->assertCreated();

        $this->assertSame('Please approve the logo', $res->json('message.approval.title'));
        $this->assertSame([], $res->json('message.approval.files'));
    }

    public function test_the_approval_notification_replaces_the_chat_one(): void
    {
        $this->chat(['file' => $this->image(), 'requires_action' => true])->assertCreated();

        Notification::assertSentTo($this->client, ApprovalRequestedNotification::class);
        Notification::assertNotSentTo($this->client, ChatMessageSentNotification::class);
    }

    public function test_a_file_without_an_approval_request_is_an_ordinary_message(): void
    {
        $res = $this->chat(['file' => $this->image()])->assertCreated();

        $this->assertNull($res->json('message.approval_id'));
        $this->assertNull(FileEntry::firstOrFail()->approval_id);
        Notification::assertSentTo($this->client, ChatMessageSentNotification::class);
        Notification::assertNotSentTo($this->client, ApprovalRequestedNotification::class);
    }

    public function test_the_chat_history_carries_the_files_of_each_approval(): void
    {
        $this->chat(['file' => $this->image(), 'requires_action' => true])->assertCreated();

        $res = $this->getJson("/api/workspaces/{$this->workspace->id}/chat")->assertOk();

        $this->assertSame('1.jpg', $res->json('messages.0.approval.files.0.name'));
        $this->assertNotEmpty($res->json('messages.0.approval.files.0.file_url'));
    }

    public function test_the_files_tab_tags_it_as_an_approval_attachment(): void
    {
        $this->chat(['file' => $this->image(), 'requires_action' => true])->assertCreated();

        $res = $this->getJson("/api/workspaces/{$this->workspace->id}/files")->assertOk();

        $this->assertSame('Approval attachment', $res->json('files.0.tag_en'));
    }

    public function test_marking_a_file_message_afterwards_links_the_file_and_notifies(): void
    {
        $message = $this->chat(['file' => $this->image('late.jpg')])->assertCreated()->json('message');
        Notification::fake();

        $res = $this->patchJson("/api/chat/{$message['id']}/require-action")->assertOk();

        $this->assertSame('late.jpg', $res->json('message.approval.title'));
        $this->assertSame('late.jpg', $res->json('message.approval.files.0.name'));
        $this->assertSame($res->json('message.approval_id'), FileEntry::firstOrFail()->approval_id);
        Notification::assertSentTo($this->client, ApprovalRequestedNotification::class);
    }

    public function test_marking_it_twice_does_not_notify_twice(): void
    {
        $message = $this->chat(['message' => 'sign off', 'requires_action' => true])->assertCreated()->json('message');
        Notification::fake();

        $this->patchJson("/api/chat/{$message['id']}/require-action")->assertOk(); // off
        $this->patchJson("/api/chat/{$message['id']}/require-action")->assertOk(); // on again

        Notification::assertNothingSent();
    }

    public function test_a_file_attached_in_the_approvals_tab_is_approved_and_shown_in_chat(): void
    {
        $res = $this->post("/api/workspaces/{$this->workspace->id}/approvals", [
            'title' => 'Brand kit',
            'files' => [$this->image('kit.jpg')],
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame('approved', FileEntry::firstOrFail()->status);
        $this->assertSame('kit.jpg', $res->json('approval.files.0.name'));

        $chat = $this->getJson("/api/workspaces/{$this->workspace->id}/chat")->assertOk();
        $this->assertSame('kit.jpg', $chat->json('messages.0.approval.files.0.name'));
        // No emoji, no fixed Arabic prefix: just what is being asked.
        $this->assertSame('Brand kit', $chat->json('messages.0.message'));
        Notification::assertSentTo($this->client, ApprovalRequestedNotification::class);
    }

    public function test_a_clients_own_file_in_chat_stays_pending(): void
    {
        // A real bearer token, as the other client-side tests do.
        $this->app['auth']->forgetGuards();
        $token = $this->client->createToken('test')->plainTextToken;
        $this->withHeaders(['Authorization' => 'Bearer ' . $token]);

        $this->chat(['file' => $this->image('mine.jpg')])->assertCreated();

        $file = FileEntry::firstOrFail();
        $this->assertSame('pending', $file->status);
        $this->assertNull($file->approval_id);
    }
}
