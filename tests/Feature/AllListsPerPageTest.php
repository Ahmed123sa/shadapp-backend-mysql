<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Meeting;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 24 Sept 2026 — GET /all-contracts and GET /all-meetings used to hardcode
 * paginate(30), silently ignoring any per_page the caller sent
 * (server-side-stats-plan.md, Stage 4, W10). Both now accept per_page (up to
 * 100, default unchanged at 30) the same way GET /all-payments already does.
 */
class AllListsPerPageTest extends TestCase
{
    use RefreshDatabase;

    private function workspaceWithManager(): array
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $client = Client::factory()->create(['manager_id' => $manager->id]);
        $workspace = Workspace::factory()->create(['client_id' => $client->id, 'manager_id' => $manager->id]);

        return [$manager, $workspace];
    }

    public function test_all_contracts_defaults_to_30_per_page_when_not_specified(): void
    {
        [$manager, $workspace] = $this->workspaceWithManager();
        Contract::factory()->count(35)->create(['workspace_id' => $workspace->id]);

        $response = $this->actingAs($manager)->getJson('/api/all-contracts')->assertOk();

        $this->assertSame(30, $response->json('contracts.per_page'));
        $this->assertCount(30, $response->json('contracts.data'));
    }

    public function test_all_contracts_honors_a_larger_per_page(): void
    {
        [$manager, $workspace] = $this->workspaceWithManager();
        Contract::factory()->count(35)->create(['workspace_id' => $workspace->id]);

        $response = $this->actingAs($manager)->getJson('/api/all-contracts?per_page=100')->assertOk();

        $this->assertSame(100, $response->json('contracts.per_page'));
        $this->assertCount(35, $response->json('contracts.data'));
    }

    public function test_all_contracts_clamps_per_page_to_100(): void
    {
        [$manager, $workspace] = $this->workspaceWithManager();
        Contract::factory()->count(5)->create(['workspace_id' => $workspace->id]);

        $response = $this->actingAs($manager)->getJson('/api/all-contracts?per_page=500')->assertOk();

        $this->assertSame(100, $response->json('contracts.per_page'));
    }

    private function createMeeting(Workspace $workspace, User $manager): void
    {
        Meeting::create([
            'workspace_id' => $workspace->id,
            'title' => 'Kickoff',
            'scheduled_at' => now()->addDay(),
            'duration_minutes' => 30,
            'status' => 'scheduled',
            'created_by' => $manager->id,
        ]);
    }

    public function test_all_meetings_defaults_to_30_per_page_when_not_specified(): void
    {
        [$manager, $workspace] = $this->workspaceWithManager();
        for ($i = 0; $i < 35; $i++) {
            $this->createMeeting($workspace, $manager);
        }

        $response = $this->actingAs($manager)->getJson('/api/all-meetings')->assertOk();

        $this->assertSame(30, $response->json('meetings.per_page'));
        $this->assertCount(30, $response->json('meetings.data'));
    }

    public function test_all_meetings_honors_a_larger_per_page(): void
    {
        [$manager, $workspace] = $this->workspaceWithManager();
        for ($i = 0; $i < 35; $i++) {
            $this->createMeeting($workspace, $manager);
        }

        $response = $this->actingAs($manager)->getJson('/api/all-meetings?per_page=100')->assertOk();

        $this->assertSame(100, $response->json('meetings.per_page'));
        $this->assertCount(35, $response->json('meetings.data'));
    }

    public function test_all_meetings_clamps_per_page_to_100(): void
    {
        [$manager, $workspace] = $this->workspaceWithManager();
        $this->createMeeting($workspace, $manager);

        $response = $this->actingAs($manager)->getJson('/api/all-meetings?per_page=500')->assertOk();

        $this->assertSame(100, $response->json('meetings.per_page'));
    }
}
