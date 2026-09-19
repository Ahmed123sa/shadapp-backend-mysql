<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Client;
use App\Models\SubUser;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 18 Sept 2026 — MeetingController::store() had no coverage at all before
 * this file. Added while fixing a bug found alongside the SubUserPlan
 * audit_logs.user_id FK fix: store() called $request->user()->isSuperAdmin()
 * unconditionally, which is a fatal "call to undefined method" for a Client
 * or SubUser (neither defines it) rather than a clean 403 — reachable
 * because this route has no {client} segment for ScopeWorkspace's
 * canBeAccessedBy() check to reject them outright.
 */
class MeetingTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private Client $client;
    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $this->client = Client::factory()->create(['manager_id' => $this->manager->id]);
        $this->workspace = Workspace::factory()->create([
            'client_id' => $this->client->id,
            'manager_id' => $this->manager->id,
        ]);
    }

    public function test_the_workspaces_manager_can_create_a_meeting(): void
    {
        $this->actingAs($this->manager)
            ->postJson("/api/workspaces/{$this->workspace->id}/meetings", [
                'title' => 'Kickoff',
                'scheduled_at' => now()->addDay()->toIso8601String(),
            ])
            ->assertStatus(201)
            ->assertJsonPath('meeting.title', 'Kickoff');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'meeting.created',
            'user_id' => $this->manager->id,
        ]);
    }

    public function test_a_client_cannot_create_a_meeting(): void
    {
        // Meetings' store route sits behind plain 'auth:sanctum', not the
        // 'auth.any:sanctum,client,sub_user' group. actingAs($client,
        // 'client') only populates the 'client' guard, which this route
        // never checks, so it would 401 before reaching the controller and
        // never actually exercise the isSuperAdmin() guard below. A real
        // client presents a genuine Sanctum bearer token instead, which
        // Sanctum's own 'sanctum' guard accepts regardless of tokenable
        // type — that's the actual path that used to crash with a fatal
        // "call to undefined method isSuperAdmin()" instead of a clean 403.
        $token = $this->client->createToken('test')->plainTextToken;

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson("/api/workspaces/{$this->workspace->id}/meetings", [
                'title' => 'Kickoff',
                'scheduled_at' => now()->addDay()->toIso8601String(),
            ])
            ->assertStatus(403);
    }

    public function test_a_sub_user_cannot_create_a_meeting(): void
    {
        $subUser = SubUser::factory()->create(['client_id' => $this->client->id]);
        $token = $subUser->createToken('test')->plainTextToken;

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson("/api/workspaces/{$this->workspace->id}/meetings", [
                'title' => 'Kickoff',
                'scheduled_at' => now()->addDay()->toIso8601String(),
            ])
            ->assertStatus(403);
    }

    public function test_a_different_managers_meeting_request_is_rejected(): void
    {
        $otherManager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);

        $this->actingAs($otherManager)
            ->postJson("/api/workspaces/{$this->workspace->id}/meetings", [
                'title' => 'Kickoff',
                'scheduled_at' => now()->addDay()->toIso8601String(),
            ])
            ->assertStatus(403);
    }
}
