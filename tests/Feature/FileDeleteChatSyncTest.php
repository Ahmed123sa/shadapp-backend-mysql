<?php

namespace Tests\Feature;

use App\Events\MessageDeleted;
use App\Models\Approval;
use App\Models\ChatMessage;
use App\Models\Client;
use App\Models\FileEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FileDeleteChatSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private Client $client;
    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $this->client = Client::factory()->create(['manager_id' => $this->manager->id]);
        $this->workspace = Workspace::factory()->create([
            'client_id' => $this->client->id,
            'manager_id' => $this->manager->id,
        ]);
    }

    public function test_deleting_chat_file_from_files_tab_deletes_chat_message_and_broadcasts_event(): void
    {
        Event::fake([MessageDeleted::class]);

        $fakeFile = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $chatResponse = $this->actingAs($this->client, 'client')
            ->postJson("/api/workspaces/{$this->workspace->id}/chat", [
                'file' => $fakeFile,
            ]);
        $chatResponse->assertCreated();

        $message = ChatMessage::where('workspace_id', $this->workspace->id)->first();
        $this->assertNotNull($message);
        $this->assertEquals('file', $message->type);

        $fileEntry = FileEntry::where('workspace_id', $this->workspace->id)->first();
        $this->assertNotNull($fileEntry);
        $this->assertEquals('pending', $fileEntry->status);

        $deleteResponse = $this->actingAs($this->client, 'client')
            ->deleteJson("/api/workspaces/{$this->workspace->id}/files/{$fileEntry->id}");

        $deleteResponse->assertOk();
        $this->assertNull(FileEntry::find($fileEntry->id));
        $this->assertNull(ChatMessage::find($message->id));

        Event::assertDispatched(MessageDeleted::class, function ($event) use ($message) {
            return $event->messageId === $message->id && $event->workspaceId === $this->workspace->id;
        });
    }

    public function test_cannot_delete_approved_file(): void
    {
        $fileEntry = FileEntry::create([
            'workspace_id' => $this->workspace->id,
            'uploaded_by_type' => Client::class,
            'uploaded_by_id' => $this->client->id,
            'file_url' => '/storage/approved.pdf',
            'name' => 'approved.pdf',
            'status' => 'approved',
        ]);

        $message = ChatMessage::create([
            'workspace_id' => $this->workspace->id,
            'sender_type' => Client::class,
            'sender_id' => $this->client->id,
            'type' => 'file',
            'file_url' => '/storage/approved.pdf',
        ]);

        $response = $this->actingAs($this->client, 'client')
            ->deleteJson("/api/workspaces/{$this->workspace->id}/files/{$fileEntry->id}");

        $response->assertStatus(422);
        $this->assertNotNull(FileEntry::find($fileEntry->id));
        $this->assertNotNull(ChatMessage::find($message->id));
    }

    public function test_deleting_regular_file_does_not_affect_unrelated_chat_messages(): void
    {
        $fileEntry = FileEntry::create([
            'workspace_id' => $this->workspace->id,
            'uploaded_by_type' => Client::class,
            'uploaded_by_id' => $this->client->id,
            'file_url' => '/storage/workspace-1/regular.pdf',
            'name' => 'regular.pdf',
            'status' => 'pending',
        ]);

        $otherMessage = ChatMessage::create([
            'workspace_id' => $this->workspace->id,
            'sender_type' => Client::class,
            'sender_id' => $this->client->id,
            'type' => 'file',
            'file_url' => '/storage/chat-attachments/other.pdf',
        ]);

        $response = $this->actingAs($this->client, 'client')
            ->deleteJson("/api/workspaces/{$this->workspace->id}/files/{$fileEntry->id}");

        $response->assertOk();
        $this->assertNull(FileEntry::find($fileEntry->id));
        $this->assertNotNull(ChatMessage::find($otherMessage->id));
    }

    public function test_chat_message_with_approval_request_is_preserved_when_file_is_deleted(): void
    {
        $approval = Approval::create([
            'workspace_id' => $this->workspace->id,
            'approvable_type' => 'chat_message',
            'approvable_id' => 1,
            'title' => 'Approval needed',
            'reference_no' => 'APP-1234567890',
            'status' => 'pending',
            'requested_by' => $this->manager->id,
        ]);

        $fileEntry = FileEntry::create([
            'workspace_id' => $this->workspace->id,
            'uploaded_by_type' => Client::class,
            'uploaded_by_id' => $this->client->id,
            'file_url' => '/storage/chat-attachments/with_approval.pdf',
            'name' => 'with_approval.pdf',
            'status' => 'pending',
        ]);

        $message = ChatMessage::create([
            'workspace_id' => $this->workspace->id,
            'sender_type' => Client::class,
            'sender_id' => $this->client->id,
            'type' => 'file',
            'file_url' => '/storage/chat-attachments/with_approval.pdf',
            'approval_id' => $approval->id,
        ]);
        $approval->update(['approvable_id' => $message->id]);

        $response = $this->actingAs($this->client, 'client')
            ->deleteJson("/api/workspaces/{$this->workspace->id}/files/{$fileEntry->id}");

        $response->assertOk();
        $this->assertNull(FileEntry::find($fileEntry->id));
        $this->assertNotNull(ChatMessage::find($message->id));
    }

    public function test_unauthorized_user_cannot_delete_file(): void
    {
        $otherClient = Client::factory()->create();

        $fileEntry = FileEntry::create([
            'workspace_id' => $this->workspace->id,
            'uploaded_by_type' => Client::class,
            'uploaded_by_id' => $this->client->id,
            'file_url' => '/storage/pending.pdf',
            'name' => 'pending.pdf',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($otherClient, 'client')
            ->deleteJson("/api/workspaces/{$this->workspace->id}/files/{$fileEntry->id}");

        $response->assertStatus(403);
        $this->assertNotNull(FileEntry::find($fileEntry->id));
    }
}
