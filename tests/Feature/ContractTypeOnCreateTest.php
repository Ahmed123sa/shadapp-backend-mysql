<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * contract_type is decided by the server from the workspace status, not
 * trusted from the caller (the dashboard chat builder sent none, which
 * defaulted extra contracts to 'main').
 */
class ContractTypeOnCreateTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(string $status): array
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $client = Client::factory()->create(['manager_id' => $manager->id]);
        $workspace = Workspace::factory()->create([
            'client_id' => $client->id,
            'manager_id' => $manager->id,
            'status' => $status,
        ]);

        return [$manager, $workspace];
    }

    public function test_a_contract_created_in_an_active_workspace_without_a_type_is_additional(): void
    {
        [$manager, $workspace] = $this->workspace('active');
        Sanctum::actingAs($manager);

        $this->postJson("/api/workspaces/{$workspace->id}/contracts", ['title' => 'Extra'])->assertCreated();

        $this->assertDatabaseHas('contracts', ['title' => 'Extra', 'contract_type' => 'additional']);
    }

    public function test_an_active_workspace_overrides_a_main_type_sent_by_the_caller(): void
    {
        [$manager, $workspace] = $this->workspace('active');
        Sanctum::actingAs($manager);

        $this->postJson("/api/workspaces/{$workspace->id}/contracts", ['title' => 'Extra', 'contract_type' => 'main'])->assertCreated();

        $this->assertDatabaseHas('contracts', ['title' => 'Extra', 'contract_type' => 'additional']);
    }

    public function test_a_contract_created_before_activation_is_main_by_default(): void
    {
        [$manager, $workspace] = $this->workspace('inactive');
        Sanctum::actingAs($manager);

        $this->postJson("/api/workspaces/{$workspace->id}/contracts", ['title' => 'Onboarding'])->assertCreated();

        $this->assertDatabaseHas('contracts', ['title' => 'Onboarding', 'contract_type' => 'main']);
    }
}
