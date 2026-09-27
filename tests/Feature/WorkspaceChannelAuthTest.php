<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\SubUser;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkspaceChannelAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['broadcasting.default' => 'reverb']);
        Broadcast::setDefaultDriver('reverb');
        require base_path('routes/channels.php');
    }

    private function createWorkspaceSetup(): array
    {
        $superAdmin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $otherManager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);

        $client = Client::factory()->create(['manager_id' => $manager->id]);
        $otherClient = Client::factory()->create(['manager_id' => $otherManager->id]);

        $workspace = Workspace::factory()->create([
            'client_id' => $client->id,
            'manager_id' => $manager->id,
        ]);

        $subUser = SubUser::factory()->create(['client_id' => $client->id]);
        $otherSubUser = SubUser::factory()->create(['client_id' => $otherClient->id]);

        return compact(
            'superAdmin',
            'manager',
            'otherManager',
            'client',
            'otherClient',
            'workspace',
            'subUser',
            'otherSubUser'
        );
    }

    public function test_super_admin_can_join_workspace_channel(): void
    {
        $setup = $this->createWorkspaceSetup();
        Sanctum::actingAs($setup['superAdmin'], ['*']);

        $response = $this->postJson('/api/broadcasting/auth', [
            'socket_id' => '1.1',
            'channel_name' => "private-workspace.{$setup['workspace']->id}",
        ]);

        $response->assertStatus(200);
    }

    public function test_managing_account_manager_can_join_workspace_channel(): void
    {
        $setup = $this->createWorkspaceSetup();
        Sanctum::actingAs($setup['manager'], ['*']);

        $response = $this->postJson('/api/broadcasting/auth', [
            'socket_id' => '1.1',
            'channel_name' => "private-workspace.{$setup['workspace']->id}",
        ]);

        $response->assertStatus(200);
    }

    public function test_other_account_manager_cannot_join_workspace_channel(): void
    {
        $setup = $this->createWorkspaceSetup();
        Sanctum::actingAs($setup['otherManager'], ['*']);

        $response = $this->postJson('/api/broadcasting/auth', [
            'socket_id' => '1.1',
            'channel_name' => "private-workspace.{$setup['workspace']->id}",
        ]);

        $response->assertStatus(403);
    }

    public function test_owning_client_can_join_workspace_channel(): void
    {
        $setup = $this->createWorkspaceSetup();
        Sanctum::actingAs($setup['client'], ['*'], 'client');

        $response = $this->postJson('/api/broadcasting/auth', [
            'socket_id' => '1.1',
            'channel_name' => "private-workspace.{$setup['workspace']->id}",
        ]);

        $response->assertStatus(200);
    }

    public function test_other_client_cannot_join_workspace_channel(): void
    {
        $setup = $this->createWorkspaceSetup();
        Sanctum::actingAs($setup['otherClient'], ['*'], 'client');

        $response = $this->postJson('/api/broadcasting/auth', [
            'socket_id' => '1.1',
            'channel_name' => "private-workspace.{$setup['workspace']->id}",
        ]);

        $response->assertStatus(403);
    }

    public function test_owning_sub_user_can_join_workspace_channel(): void
    {
        $setup = $this->createWorkspaceSetup();
        Sanctum::actingAs($setup['subUser'], ['*'], 'sub_user');

        $response = $this->postJson('/api/broadcasting/auth', [
            'socket_id' => '1.1',
            'channel_name' => "private-workspace.{$setup['workspace']->id}",
        ]);

        $response->assertStatus(200);
    }

    public function test_other_sub_user_cannot_join_workspace_channel(): void
    {
        $setup = $this->createWorkspaceSetup();
        Sanctum::actingAs($setup['otherSubUser'], ['*'], 'sub_user');

        $response = $this->postJson('/api/broadcasting/auth', [
            'socket_id' => '1.1',
            'channel_name' => "private-workspace.{$setup['workspace']->id}",
        ]);

        $response->assertStatus(403);
    }

    public function test_cannot_join_non_existent_workspace_channel(): void
    {
        $setup = $this->createWorkspaceSetup();
        Sanctum::actingAs($setup['superAdmin'], ['*']);

        $response = $this->postJson('/api/broadcasting/auth', [
            'socket_id' => '1.1',
            'channel_name' => 'private-workspace.999999',
        ]);

        $response->assertStatus(403);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $setup = $this->createWorkspaceSetup();

        $response = $this->postJson('/api/broadcasting/auth', [
            'socket_id' => '1.1',
            'channel_name' => "private-workspace.{$setup['workspace']->id}",
        ]);

        $response->assertStatus(401);
    }
}