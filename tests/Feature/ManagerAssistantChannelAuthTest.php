<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** ت١٣ — live chat works for an assistant on their manager's clients only. */
class ManagerAssistantChannelAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['broadcasting.default' => 'reverb']);
        Broadcast::setDefaultDriver('reverb');
        require base_path('routes/channels.php');
    }

    private function join(User $user, Workspace $workspace)
    {
        Sanctum::actingAs($user, ['*']);

        return $this->postJson('/api/broadcasting/auth', [
            'socket_id' => '1.1',
            'channel_name' => "private-workspace.{$workspace->id}",
        ]);
    }

    public function test_an_assistant_joins_their_managers_workspace_channel_but_not_anothers(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $other = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $assistant = User::factory()->create([
            'role' => User::ROLE_MANAGER_ASSISTANT,
            'parent_manager_id' => $manager->id,
            'assistant_permissions' => [],
        ]);

        $mine = Workspace::factory()->create([
            'client_id' => Client::factory()->create(['manager_id' => $manager->id])->id,
            'manager_id' => $manager->id,
        ]);
        $theirs = Workspace::factory()->create([
            'client_id' => Client::factory()->create(['manager_id' => $other->id])->id,
            'manager_id' => $other->id,
        ]);

        $this->join($assistant, $mine)->assertOk();
        $this->join($assistant, $theirs)->assertForbidden();
    }
}
