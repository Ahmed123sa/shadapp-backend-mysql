<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Contract;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * STAFF_TEAMS_PLAN.md §٣.٤ — the super admin can let a specific account
 * manager company-approve contracts for their own clients. The contract is
 * always signed with the company (super admin) signature.
 */
class ManagerContractApprovalTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private User $manager;
    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'signature_data' => 'توقيع الشركة',
        ]);
        $this->manager = User::factory()->create([
            'role' => User::ROLE_ACCOUNT_MANAGER,
            'super_admin_id' => $this->superAdmin->id,
            'signature_data' => 'توقيع المدير الشخصي',
            'is_active' => true,
        ]);
        $client = Client::factory()->create(['manager_id' => $this->manager->id]);
        // Not yet active, so a company approval lands on `company_approved`
        // (an active workspace completes the contract straight away).
        $this->workspace = Workspace::factory()->create([
            'client_id' => $client->id,
            'manager_id' => $this->manager->id,
            'status' => 'inactive',
        ]);
    }

    private function awaitingCompany(?Workspace $ws = null): Contract
    {
        return Contract::factory()->create([
            'workspace_id' => ($ws ?? $this->workspace)->id,
            'status' => 'client_approved',
            'created_by' => $this->manager->id,
        ]);
    }

    public function test_a_manager_without_the_permission_cannot_approve(): void
    {
        $contract = $this->awaitingCompany();
        Sanctum::actingAs($this->manager);

        $this->postJson("/api/contracts/{$contract->id}/company-approve")->assertForbidden();
        $this->assertSame('client_approved', $contract->fresh()->status);
    }

    public function test_a_manager_with_the_permission_approves_with_the_company_signature(): void
    {
        $this->manager->update(['can_approve_contracts' => true]);
        $contract = $this->awaitingCompany();
        Sanctum::actingAs($this->manager);

        // Even if a signature is sent, the company one is used.
        $this->postJson("/api/contracts/{$contract->id}/company-approve", ['signature' => 'محاولة توقيع'])->assertOk();

        $fresh = $contract->fresh();
        $this->assertSame('company_approved', $fresh->status);
        $this->assertSame('توقيع الشركة', $fresh->company_signature_data);
        $this->assertDatabaseHas('audit_logs', [
            'auditable_id' => $contract->id,
            'action' => 'contract.company_approved',
            'user_id' => $this->manager->id,
        ]);
    }

    public function test_the_permission_only_covers_the_managers_own_clients(): void
    {
        $this->manager->update(['can_approve_contracts' => true]);
        $other = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $otherClient = Client::factory()->create(['manager_id' => $other->id]);
        $otherWs = Workspace::factory()->create(['client_id' => $otherClient->id, 'manager_id' => $other->id]);
        $contract = $this->awaitingCompany($otherWs);
        Sanctum::actingAs($this->manager);

        $this->postJson("/api/contracts/{$contract->id}/company-approve")->assertForbidden();
    }

    public function test_without_a_saved_company_signature_the_manager_gets_a_clear_422(): void
    {
        $this->superAdmin->update(['signature_data' => null]);
        $this->manager->update(['can_approve_contracts' => true]);
        $contract = $this->awaitingCompany();
        Sanctum::actingAs($this->manager);

        $this->postJson("/api/contracts/{$contract->id}/company-approve")
            ->assertStatus(422)
            ->assertJsonPath('code', 'signature_required');
        $this->assertSame('client_approved', $contract->fresh()->status);
    }

    public function test_the_super_admin_still_approves_as_before(): void
    {
        $contract = $this->awaitingCompany();
        Sanctum::actingAs($this->superAdmin);

        $this->postJson("/api/contracts/{$contract->id}/company-approve", ['use_saved_signature' => true])->assertOk();
        $this->assertSame('company_approved', $contract->fresh()->status);
    }

    public function test_the_super_admin_grants_and_revokes_the_permission(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $this->putJson("/api/account-managers/{$this->manager->id}", ['can_approve_contracts' => true])->assertOk();
        $this->assertTrue($this->manager->fresh()->can_approve_contracts);
        $this->assertTrue(AuditLog::where('action', 'account_manager.contract_approval_granted')->exists());

        $this->putJson("/api/account-managers/{$this->manager->id}", ['can_approve_contracts' => false])->assertOk();
        $this->assertFalse($this->manager->fresh()->can_approve_contracts);
        $this->assertTrue(AuditLog::where('action', 'account_manager.contract_approval_revoked')->exists());
    }

    public function test_a_manager_cannot_grant_the_permission_to_themselves(): void
    {
        Sanctum::actingAs($this->manager);

        $this->putJson("/api/account-managers/{$this->manager->id}", ['can_approve_contracts' => true])->assertForbidden();
        $this->assertFalse((bool) $this->manager->fresh()->can_approve_contracts);
    }

    public function test_an_assistant_never_approves_even_if_the_manager_can(): void
    {
        $this->manager->update(['can_approve_contracts' => true]);
        $assistant = User::factory()->create([
            'role' => User::ROLE_MANAGER_ASSISTANT,
            'parent_manager_id' => $this->manager->id,
            'assistant_permissions' => ['can_view_clients' => true, 'can_manage_contracts' => true],
            'is_active' => true,
        ]);
        $contract = $this->awaitingCompany();
        Sanctum::actingAs($assistant);

        $this->postJson("/api/contracts/{$contract->id}/company-approve")->assertForbidden();
    }

    public function test_login_tells_the_manager_whether_they_can_approve(): void
    {
        $this->manager->update(['can_approve_contracts' => true, 'password' => 'Password123']);

        $this->postJson('/api/auth/login', ['email' => $this->manager->email, 'password' => 'Password123'])
            ->assertOk()
            ->assertJsonPath('user.can_approve_contracts', true);
    }
}
