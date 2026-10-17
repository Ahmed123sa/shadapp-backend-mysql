<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\ChatMessage;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Meeting;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\ApprovalRequestedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase ف٣ (STAFF_TEAMS_PLAN.md): company staff are assigned to clients and
 * can then — on those clients only — read and write the chat, send approval
 * requests and join meetings. Everything else stays closed.
 */
class StaffAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private User $managerA;
    private User $managerB;
    private User $staff;
    private User $otherStaff;
    private Client $clientA;
    private Client $clientB;
    private Workspace $wsA;
    private Workspace $wsB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $this->managerA = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER, 'super_admin_id' => $this->superAdmin->id, 'is_active' => true]);
        $this->managerB = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER, 'super_admin_id' => $this->superAdmin->id, 'is_active' => true]);
        $this->staff = $this->member('Huda');
        $this->otherStaff = $this->member('Omar');

        $this->clientA = Client::factory()->create(['manager_id' => $this->managerA->id, 'contract_value' => 5000, 'notes' => 'secret note']);
        $this->clientB = Client::factory()->create(['manager_id' => $this->managerB->id]);
        $this->wsA = Workspace::factory()->create(['client_id' => $this->clientA->id, 'manager_id' => $this->managerA->id, 'status' => 'active']);
        $this->wsB = Workspace::factory()->create(['client_id' => $this->clientB->id, 'manager_id' => $this->managerB->id, 'status' => 'active']);
    }

    private function member(string $name): User
    {
        return User::factory()->create([
            'name' => $name,
            'role' => User::ROLE_STAFF_MEMBER,
            'is_active' => true,
            'password' => 'Password123',
        ]);
    }

    private function assign(User $member, Workspace $ws): void
    {
        $ws->staff()->syncWithoutDetaching([$member->id => ['assigned_by' => $this->superAdmin->id]]);
    }

    // ── assigning ──

    public function test_a_manager_assigns_and_unassigns_staff_on_their_own_client(): void
    {
        Sanctum::actingAs($this->managerA);

        $this->putJson("/api/workspaces/{$this->wsA->id}/staff/{$this->staff->id}")
            ->assertOk()
            ->assertJsonPath('staff.assigned', true)
            ->assertJsonPath('changed', true);

        $this->assertDatabaseHas('workspace_staff', ['workspace_id' => $this->wsA->id, 'user_id' => $this->staff->id, 'assigned_by' => $this->managerA->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'staff.assigned', 'auditable_id' => $this->staff->id, 'client_id' => $this->clientA->id, 'user_id' => $this->managerA->id]);

        $this->deleteJson("/api/workspaces/{$this->wsA->id}/staff/{$this->staff->id}")
            ->assertOk()
            ->assertJsonPath('staff.assigned', false)
            ->assertJsonPath('changed', true);

        $this->assertDatabaseMissing('workspace_staff', ['workspace_id' => $this->wsA->id, 'user_id' => $this->staff->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'staff.unassigned', 'auditable_id' => $this->staff->id]);
    }

    public function test_assigning_twice_changes_nothing_the_second_time(): void
    {
        Sanctum::actingAs($this->managerA);

        $this->putJson("/api/workspaces/{$this->wsA->id}/staff/{$this->staff->id}")->assertOk();
        $this->putJson("/api/workspaces/{$this->wsA->id}/staff/{$this->staff->id}")->assertOk()->assertJsonPath('changed', false);

        $this->assertSame(1, $this->wsA->staff()->count());
        $this->assertSame(1, \App\Models\AuditLog::where('action', 'staff.assigned')->count());

        $this->deleteJson("/api/workspaces/{$this->wsA->id}/staff/{$this->staff->id}")->assertOk();
        $this->deleteJson("/api/workspaces/{$this->wsA->id}/staff/{$this->staff->id}")->assertOk()->assertJsonPath('changed', false);
    }

    public function test_a_manager_cannot_touch_staff_on_another_managers_client(): void
    {
        Sanctum::actingAs($this->managerA);

        $this->putJson("/api/workspaces/{$this->wsB->id}/staff/{$this->staff->id}")->assertForbidden();
        $this->getJson("/api/workspaces/{$this->wsB->id}/staff")->assertForbidden();
        $this->assertSame(0, $this->wsB->staff()->count());
    }

    public function test_the_super_admin_assigns_on_any_client(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $this->putJson("/api/workspaces/{$this->wsA->id}/staff/{$this->staff->id}")->assertOk();
        $this->putJson("/api/workspaces/{$this->wsB->id}/staff/{$this->staff->id}")->assertOk();

        $this->assertSame(2, $this->staff->assignedWorkspaces()->count());
    }

    public function test_assistants_clients_and_staff_cannot_manage_assignments(): void
    {
        $assistant = User::factory()->create(['role' => User::ROLE_MANAGER_ASSISTANT, 'parent_manager_id' => $this->managerA->id, 'assistant_permissions' => ['can_view_clients' => true], 'is_active' => true]);
        $this->assign($this->otherStaff, $this->wsA);

        foreach ([$assistant, $this->clientA, $this->otherStaff] as $actor) {
            Sanctum::actingAs($actor);
            $this->putJson("/api/workspaces/{$this->wsA->id}/staff/{$this->staff->id}")->assertForbidden();
            $this->deleteJson("/api/workspaces/{$this->wsA->id}/staff/{$this->otherStaff->id}")->assertForbidden();
            $this->getJson("/api/workspaces/{$this->wsA->id}/staff")->assertForbidden();
        }

        $this->assertSame(1, $this->wsA->staff()->count());
    }

    public function test_only_active_staff_members_can_be_assigned(): void
    {
        $archived = $this->member('Old');
        $archived->forceFill(['is_active' => false, 'archived_at' => now()])->save();
        Sanctum::actingAs($this->managerA);

        $this->putJson("/api/workspaces/{$this->wsA->id}/staff/{$archived->id}")->assertUnprocessable();
        // A manager or any other kind of user is not a staff member.
        $this->putJson("/api/workspaces/{$this->wsA->id}/staff/{$this->managerA->id}")->assertNotFound();
        $this->putJson("/api/workspaces/{$this->wsA->id}/staff/{$this->managerB->id}")->assertNotFound();

        $this->assertSame(0, $this->wsA->staff()->count());
    }

    public function test_the_team_tab_lists_active_staff_with_their_assignment(): void
    {
        $archived = $this->member('Old');
        $archived->forceFill(['is_active' => false, 'archived_at' => now()])->save();
        $this->assign($this->staff, $this->wsA);
        Sanctum::actingAs($this->managerA);

        $rows = collect($this->getJson("/api/workspaces/{$this->wsA->id}/staff")->assertOk()->json('staff'));

        $this->assertEqualsCanonicalizing([$this->staff->id, $this->otherStaff->id], $rows->pluck('id')->all());
        $this->assertTrue($rows->firstWhere('id', $this->staff->id)['assigned']);
        $this->assertFalse($rows->firstWhere('id', $this->otherStaff->id)['assigned']);
    }

    public function test_one_staff_member_can_be_on_many_clients_under_different_managers(): void
    {
        $this->assign($this->staff, $this->wsA);
        $this->assign($this->staff, $this->wsB);
        $this->assign($this->otherStaff, $this->wsA);

        Sanctum::actingAs($this->staff);
        $ids = collect($this->getJson('/api/clients')->assertOk()->json('clients.data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$this->clientA->id, $this->clientB->id], $ids);

        Sanctum::actingAs($this->otherStaff);
        $ids = collect($this->getJson('/api/clients')->json('clients.data'))->pluck('id')->all();
        $this->assertSame([$this->clientA->id], $ids);

        // And a client can have several staff.
        $this->assertSame(2, $this->wsA->staff()->count());
    }

    public function test_the_super_admin_sees_how_many_clients_each_staff_member_has(): void
    {
        $this->assign($this->staff, $this->wsA);
        $this->assign($this->staff, $this->wsB);
        Sanctum::actingAs($this->superAdmin);

        $rows = collect($this->getJson('/api/company-staff')->assertOk()->json('staff'));
        $this->assertSame(2, $rows->firstWhere('id', $this->staff->id)['assigned_clients_count']);
        $this->assertSame(0, $rows->firstWhere('id', $this->otherStaff->id)['assigned_clients_count']);

        $clients = $this->getJson("/api/company-staff/{$this->staff->id}/clients")->assertOk()->json('clients');
        $this->assertEqualsCanonicalizing([$this->wsA->id, $this->wsB->id], collect($clients)->pluck('workspace_id')->all());

        Sanctum::actingAs($this->managerA);
        $this->getJson("/api/company-staff/{$this->staff->id}/clients")->assertForbidden();
    }

    // ── what staff see ──

    public function test_staff_see_only_assigned_clients_and_only_the_basic_card(): void
    {
        $this->assign($this->staff, $this->wsA);
        Contract::factory()->create(['workspace_id' => $this->wsA->id, 'created_by' => $this->managerA->id]);
        Sanctum::actingAs($this->staff);

        $response = $this->getJson('/api/clients')->assertOk();
        $this->assertSame([$this->clientA->id], collect($response->json('clients.data'))->pluck('id')->all());
        $response->assertJsonMissingPath('clients.data.0.contract_value')
            ->assertJsonMissingPath('clients.data.0.notes')
            ->assertJsonMissingPath('clients.data.0.payments')
            ->assertJsonMissingPath('clients.data.0.sub_users')
            ->assertJsonMissingPath('clients.data.0.password');

        $this->getJson("/api/clients/{$this->clientA->id}")
            ->assertOk()
            ->assertJsonPath('client.company_name', $this->clientA->company_name)
            ->assertJsonPath('client.workspace.id', $this->wsA->id)
            ->assertJsonMissingPath('client.contract_value')
            ->assertJsonMissingPath('client.notes')
            ->assertJsonMissingPath('client.workspace.contracts')
            ->assertJsonMissingPath('client.workspace.payments')
            ->assertJsonMissingPath('client.sub_users');

        $this->getJson("/api/clients/{$this->clientB->id}")->assertForbidden();
        $this->getJson("/api/clients/{$this->clientA->uuid}")->assertOk();
    }

    public function test_staff_see_the_workspace_without_contracts_payments_or_files(): void
    {
        $this->assign($this->staff, $this->wsA);
        Sanctum::actingAs($this->staff);

        $response = $this->getJson("/api/workspaces/{$this->wsA->id}")->assertOk();
        $response->assertJsonPath('workspace.id', $this->wsA->id)
            ->assertJsonPath('workspace.client.company_name', $this->clientA->company_name)
            ->assertJsonMissingPath('workspace.contracts')
            ->assertJsonMissingPath('workspace.payments')
            ->assertJsonMissingPath('workspace.files')
            ->assertJsonMissingPath('workspace.client.contract_value')
            ->assertJsonMissingPath('nextPayment');
        $this->assertArrayHasKey('approvals', $response->json('workspace'));
        $this->assertArrayHasKey('meetings', $response->json('workspace'));

        $this->getJson("/api/workspaces/{$this->wsB->id}")->assertForbidden();
    }

    // ── chat ──

    public function test_staff_read_and_write_the_chat_of_assigned_clients_only(): void
    {
        $this->assign($this->staff, $this->wsA);
        ChatMessage::create(['workspace_id' => $this->wsA->id, 'sender_type' => Client::class, 'sender_id' => $this->clientA->id, 'message' => 'hello from client', 'type' => 'text']);
        Sanctum::actingAs($this->staff);

        $messages = $this->getJson("/api/workspaces/{$this->wsA->id}/chat")->assertOk()->json('messages');
        $this->assertSame('hello from client', $messages[0]['message']);

        $this->postJson("/api/workspaces/{$this->wsA->id}/chat", ['message' => 'hi, I am Huda'])
            ->assertCreated()
            ->assertJsonPath('message.sender.name', 'Huda');
        $this->assertDatabaseHas('chat_messages', ['workspace_id' => $this->wsA->id, 'sender_type' => User::class, 'sender_id' => $this->staff->id, 'message' => 'hi, I am Huda']);

        $this->postJson("/api/workspaces/{$this->wsA->id}/chat/mark-read")->assertOk();

        $this->getJson("/api/workspaces/{$this->wsB->id}/chat")->assertForbidden();
        $this->postJson("/api/workspaces/{$this->wsB->id}/chat", ['message' => 'nope'])->assertForbidden();
        $this->postJson("/api/workspaces/{$this->wsB->id}/chat/mark-read")->assertForbidden();
    }

    public function test_a_staff_message_notifies_the_client(): void
    {
        Notification::fake();
        $this->assign($this->staff, $this->wsA);
        Sanctum::actingAs($this->staff);

        $this->postJson("/api/workspaces/{$this->wsA->id}/chat", ['message' => 'hi'])->assertCreated();

        Notification::assertSentTo($this->clientA, \App\Notifications\ChatMessageSentNotification::class);
    }

    public function test_unassigning_cuts_access_on_the_next_request(): void
    {
        $this->assign($this->staff, $this->wsA);
        Sanctum::actingAs($this->staff);

        $this->getJson("/api/workspaces/{$this->wsA->id}/chat")->assertOk();
        $this->getJson("/api/clients/{$this->clientA->id}")->assertOk();

        $this->wsA->staff()->detach($this->staff->id);

        $this->getJson("/api/workspaces/{$this->wsA->id}/chat")->assertForbidden();
        $this->postJson("/api/workspaces/{$this->wsA->id}/chat", ['message' => 'still here?'])->assertForbidden();
        $this->getJson("/api/clients/{$this->clientA->id}")->assertForbidden();
        $this->assertSame([], $this->getJson('/api/clients')->json('clients.data'));
    }

    public function test_staff_edit_their_own_message_but_not_after_being_unassigned(): void
    {
        $this->assign($this->staff, $this->wsA);
        Sanctum::actingAs($this->staff);

        $id = $this->postJson("/api/workspaces/{$this->wsA->id}/chat", ['message' => 'first'])->json('message.id');
        $this->putJson("/api/chat/{$id}", ['message' => 'edited'])->assertOk();
        $this->assertDatabaseHas('chat_messages', ['id' => $id, 'message' => 'edited']);

        // Someone else's message is not editable.
        $clientMsg = ChatMessage::create(['workspace_id' => $this->wsA->id, 'sender_type' => Client::class, 'sender_id' => $this->clientA->id, 'message' => 'mine', 'type' => 'text']);
        $this->putJson("/api/chat/{$clientMsg->id}", ['message' => 'hijack'])->assertForbidden();

        $this->wsA->staff()->detach($this->staff->id);
        $this->putJson("/api/chat/{$id}", ['message' => 'after removal'])->assertForbidden();
        $this->assertDatabaseHas('chat_messages', ['id' => $id, 'message' => 'edited']);
    }

    // ── approval requests ──

    public function test_staff_send_approval_requests_to_the_client(): void
    {
        Notification::fake();
        $this->assign($this->staff, $this->wsA);
        Sanctum::actingAs($this->staff);

        $this->postJson("/api/workspaces/{$this->wsA->id}/approvals", ['title' => 'Approve the logo', 'description' => 'v2'])
            ->assertCreated()
            ->assertJsonPath('approval.title', 'Approve the logo');

        $approval = Approval::where('workspace_id', $this->wsA->id)->firstOrFail();
        $this->assertSame($this->staff->id, (int) $approval->requested_by);
        $this->assertSame('pending', $approval->status);
        // The request also lands in the chat as an actionable card.
        $this->assertDatabaseHas('chat_messages', ['workspace_id' => $this->wsA->id, 'approval_id' => $approval->id, 'requires_action' => true, 'sender_id' => $this->staff->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'approval.created', 'user_id' => $this->staff->id]);

        // The client must answer; the manager is told because someone else raised it.
        Notification::assertSentTo($this->clientA, ApprovalRequestedNotification::class);
        Notification::assertSentTo($this->managerA, ApprovalRequestedNotification::class);

        $list = $this->getJson("/api/workspaces/{$this->wsA->id}/approvals")->assertOk()->json('approvals.data');
        $this->assertCount(1, $list);

        $this->postJson("/api/workspaces/{$this->wsB->id}/approvals", ['title' => 'x'])->assertForbidden();
        $this->getJson("/api/workspaces/{$this->wsB->id}/approvals")->assertForbidden();
    }

    // ── meetings ──

    private function meeting(Workspace $ws, array $overrides = []): Meeting
    {
        return Meeting::create(array_merge([
            'workspace_id' => $ws->id,
            'title' => 'Kick-off',
            'scheduled_at' => now()->addDay(),
            'duration_minutes' => 30,
            'status' => 'scheduled',
            'created_by' => $ws->manager_id,
            'zoom_meeting_id' => '123456789',
            'link' => 'https://zoom.us/j/123456789',
            'passcode' => 'secret123',
        ], $overrides));
    }

    public function test_staff_see_and_join_meetings_as_participants_never_as_host(): void
    {
        Cache::flush();
        config(['services.zoom.account_id' => 'a', 'services.zoom.client_id' => 'b', 'services.zoom.client_secret' => 'c']);
        Http::fake();
        $this->assign($this->staff, $this->wsA);
        $meeting = $this->meeting($this->wsA);
        $other = $this->meeting($this->wsB);
        Sanctum::actingAs($this->staff);

        $titles = collect($this->getJson("/api/workspaces/{$this->wsA->id}/meetings")->assertOk()->json('meetings.data'))->pluck('id')->all();
        $this->assertSame([$meeting->id], $titles);

        $this->postJson("/api/meetings/{$meeting->id}/enter")
            ->assertOk()
            ->assertJsonPath('as', 'participant')
            ->assertJsonPath('url', 'https://zoom.us/j/123456789');

        // No host slot was taken and Zoom was never asked for a start link.
        $this->assertNull(Cache::get($meeting->hostCacheKey()));
        Http::assertNothingSent();

        $this->postJson("/api/meetings/{$other->id}/enter")->assertForbidden();
        $this->getJson("/api/workspaces/{$this->wsB->id}/meetings")->assertForbidden();
    }

    public function test_the_manager_still_becomes_host_when_staff_joined_first(): void
    {
        Cache::flush();
        config(['services.zoom.account_id' => 'a', 'services.zoom.client_id' => 'b', 'services.zoom.client_secret' => 'c']);
        Http::fake([
            'https://zoom.us/oauth/token*' => Http::response(['access_token' => 't', 'expires_in' => 3600], 200),
            'https://api.zoom.us/v2/meetings/*' => Http::response(['id' => 123456789, 'status' => 'waiting', 'start_url' => 'https://zoom.us/s/1?zak=x', 'join_url' => 'https://zoom.us/j/123456789'], 200),
        ]);
        $this->assign($this->staff, $this->wsA);
        $meeting = $this->meeting($this->wsA);

        Sanctum::actingAs($this->staff);
        $this->postJson("/api/meetings/{$meeting->id}/enter")->assertJsonPath('as', 'participant');

        Sanctum::actingAs($this->managerA);
        $this->postJson("/api/meetings/{$meeting->id}/enter")->assertJsonPath('as', 'host');
    }

    // ── still closed, even on an assigned client ──

    public function test_everything_outside_chat_approvals_and_meetings_stays_closed(): void
    {
        $this->assign($this->staff, $this->wsA);
        $contract = Contract::factory()->create(['workspace_id' => $this->wsA->id, 'created_by' => $this->managerA->id]);
        $meeting = $this->meeting($this->wsA);
        $chat = ChatMessage::create(['workspace_id' => $this->wsA->id, 'sender_type' => Client::class, 'sender_id' => $this->clientA->id, 'message' => 'x', 'type' => 'text']);
        Sanctum::actingAs($this->staff);

        $ws = $this->wsA->id;
        $closed = [
            ['getJson', "/api/workspaces/{$ws}/contracts"],
            ['getJson', "/api/workspaces/{$ws}/payments"],
            ['getJson', "/api/workspaces/{$ws}/payment-schedule"],
            ['getJson', "/api/workspaces/{$ws}/files"],
            ['postJson', "/api/workspaces/{$ws}/contracts"],
            ['postJson', "/api/contracts/{$contract->id}/send"],
            ['postJson', "/api/contracts/{$contract->id}/company-approve"],
            ['getJson', "/api/contracts/{$contract->id}/files"],
            ['getJson', "/api/clients/{$this->clientA->id}/sub-users"],
            ['putJson', "/api/clients/{$this->clientA->id}"],
            ['postJson', "/api/clients/{$this->clientA->id}/archive"],
            ['postJson', "/api/clients/{$this->clientA->id}/transfer"],
            ['postJson', "/api/workspaces/{$ws}/meetings"],
            ['patchJson', "/api/meetings/{$meeting->id}/complete"],
            ['patchJson', "/api/meetings/{$meeting->id}/cancel"],
            ['deleteJson', "/api/workspaces/{$ws}/meetings/{$meeting->id}"],
            ['patchJson', "/api/chat/{$chat->id}/require-action"],
            ['postJson', "/api/chat/{$chat->id}/respond"],
            ['getJson', '/api/all-payments'],
            ['getJson', '/api/dashboard/stats'],
            ['getJson', '/api/badge-counts'],
            ['getJson', "/api/workspaces/{$ws}/staff"],
            ['putJson', "/api/workspaces/{$ws}/staff/{$this->otherStaff->id}"],
        ];

        foreach ($closed as [$method, $path]) {
            $status = $this->{$method}($path)->getStatusCode();
            $this->assertSame(403, $status, "{$method} {$path} answered {$status}, expected 403");
        }
    }

    // ── archive ──

    public function test_archiving_a_staff_member_cuts_access_and_unarchiving_restores_it(): void
    {
        $this->assign($this->staff, $this->wsA);
        $token = $this->staff->createToken('t')->plainTextToken;

        Sanctum::actingAs($this->superAdmin);
        $this->postJson("/api/company-staff/{$this->staff->id}/archive")->assertOk();

        // The assignment is kept, but it grants nothing while archived.
        $this->assertSame(1, $this->staff->assignedWorkspaces()->count());
        $this->assertFalse($this->wsA->fresh()->canBeAccessedBy($this->staff->fresh()));

        // …and the team tab no longer offers them.
        Sanctum::actingAs($this->managerA);
        $ids = collect($this->getJson("/api/workspaces/{$this->wsA->id}/staff")->json('staff'))->pluck('id')->all();
        $this->assertNotContains($this->staff->id, $ids);

        Sanctum::actingAs($this->superAdmin);
        $this->postJson("/api/company-staff/{$this->staff->id}/unarchive")->assertOk();
        $this->assertTrue($this->wsA->fresh()->canBeAccessedBy($this->staff->fresh()));
    }

    // ── transfer ──

    public function test_staff_stay_with_the_client_when_it_moves_to_another_manager(): void
    {
        $this->assign($this->staff, $this->wsA);

        Sanctum::actingAs($this->superAdmin);
        $this->postJson("/api/clients/{$this->clientA->id}/transfer", ['new_manager_id' => $this->managerB->id])->assertOk();

        $this->assertSame($this->managerB->id, (int) $this->wsA->fresh()->manager_id);
        $this->assertSame(1, $this->wsA->staff()->count());

        // Still works for the staff member…
        Sanctum::actingAs($this->staff);
        $this->getJson("/api/workspaces/{$this->wsA->id}/chat")->assertOk();

        // …the new manager sees them on the team tab and may remove them…
        Sanctum::actingAs($this->managerB);
        $row = collect($this->getJson("/api/workspaces/{$this->wsA->id}/staff")->assertOk()->json('staff'))->firstWhere('id', $this->staff->id);
        $this->assertTrue($row['assigned']);
        $this->deleteJson("/api/workspaces/{$this->wsA->id}/staff/{$this->staff->id}")->assertOk();

        // …and the old manager can no longer manage this client's team.
        Sanctum::actingAs($this->managerA);
        $this->putJson("/api/workspaces/{$this->wsA->id}/staff/{$this->staff->id}")->assertForbidden();

        Sanctum::actingAs($this->staff);
        $this->getJson("/api/workspaces/{$this->wsA->id}/chat")->assertForbidden();
    }

    // ── realtime ──

    private function channelAuth(User $user, Workspace $ws)
    {
        config(['broadcasting.default' => 'reverb']);
        Broadcast::setDefaultDriver('reverb');
        require base_path('routes/channels.php');

        Sanctum::actingAs($user, ['*']);

        return $this->postJson('/api/broadcasting/auth', [
            'socket_id' => '1.1',
            'channel_name' => "private-workspace.{$ws->id}",
        ]);
    }

    public function test_staff_join_the_realtime_channel_of_assigned_clients_only(): void
    {
        $this->assign($this->staff, $this->wsA);

        $this->channelAuth($this->staff, $this->wsA)->assertOk();
        $this->channelAuth($this->staff, $this->wsB)->assertForbidden();
        $this->channelAuth($this->otherStaff, $this->wsA)->assertForbidden();

        $this->wsA->staff()->detach($this->staff->id);
        $this->channelAuth($this->staff, $this->wsA)->assertForbidden();
    }
}
