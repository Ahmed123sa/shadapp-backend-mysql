<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Meeting;
use App\Models\Payment;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Scoping is closed by default: only a super admin sees company-wide data.
 * Any other staff user — including a role that doesn't exist yet — is
 * restricted to the clients of User::ownerManagerId(). Before this, every
 * list did "if account manager → restrict, else everything", so a new staff
 * role would have silently inherited super-admin reach.
 *
 * 'future_staff' stands in for such a role: it owns no clients, so it must
 * see nothing, while the real manager and the super admin keep their view.
 */
class StaffScopeClosedByDefaultTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private User $superAdmin;
    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $this->manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $this->outsider = User::factory()->create(['role' => 'future_staff']);

        $client = Client::factory()->create(['manager_id' => $this->manager->id]);
        $workspace = Workspace::factory()->create([
            'client_id' => $client->id,
            'manager_id' => $this->manager->id,
            'status' => 'active',
        ]);
        $contract = Contract::factory()->create(['workspace_id' => $workspace->id, 'status' => 'sent']);
        Payment::factory()->create(['workspace_id' => $workspace->id, 'client_id' => $client->id, 'status' => 'pending']);
        Meeting::create([
            'workspace_id' => $workspace->id,
            'contract_id' => $contract->id,
            'title' => 'Kickoff',
            'scheduled_at' => now()->addDay(),
            'duration_minutes' => 30,
            'status' => 'scheduled',
        ]);
    }

    public static function lists(): array
    {
        return [
            'payments' => ['/api/all-payments', 'payments.data'],
            'pending payments' => ['/api/payments/pending', 'payments.data'],
            'contracts' => ['/api/all-contracts', 'contracts.data'],
            'meetings' => ['/api/all-meetings', 'meetings.data'],
        ];
    }

    #[DataProvider('lists')]
    public function test_an_unknown_staff_role_is_refused_by_the_policy(string $url, string $path): void
    {
        Sanctum::actingAs($this->outsider);

        $this->getJson($url)->assertForbidden();
    }

    /**
     * The policy is the first layer. This bypasses it on purpose to prove the
     * query layer is closed on its own: if a future policy lets a new role
     * through, it still must not see anyone else's rows.
     */
    #[DataProvider('lists')]
    public function test_the_query_layer_alone_shows_a_non_super_admin_nothing(string $url, string $path): void
    {
        Gate::before(fn ($user) => $user instanceof User && $user->role === 'future_staff' ? true : null);
        Sanctum::actingAs($this->outsider);

        $this->getJson($url)->assertOk()->assertJsonCount(0, $path);
    }

    #[DataProvider('lists')]
    public function test_the_owning_manager_still_sees_their_rows(string $url, string $path): void
    {
        Sanctum::actingAs($this->manager);

        $this->getJson($url)->assertOk()->assertJsonCount(1, $path);
    }

    #[DataProvider('lists')]
    public function test_super_admin_still_sees_everything(string $url, string $path): void
    {
        Sanctum::actingAs($this->superAdmin);

        $this->getJson($url)->assertOk()->assertJsonCount(1, $path);
    }

    public function test_dashboard_stats_are_scoped_for_a_non_super_admin(): void
    {
        Sanctum::actingAs($this->outsider);

        $this->getJson('/api/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('clients.total', 0)
            ->assertJsonPath('contracts.awaiting_client', 0)
            ->assertJsonPath('payments.pending', 0);

        Sanctum::actingAs($this->manager);

        $this->getJson('/api/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('clients.total', 1)
            ->assertJsonPath('contracts.awaiting_client', 1)
            ->assertJsonPath('payments.pending', 1);
    }

    public function test_reports_are_scoped_for_a_non_super_admin(): void
    {
        Sanctum::actingAs($this->outsider);

        $this->getJson('/api/reports')->assertOk()->assertJsonPath('total_clients', 0);
    }

    public function test_pending_approvals_list_is_scoped_for_a_non_super_admin(): void
    {
        Sanctum::actingAs($this->outsider);

        $this->getJson('/api/dashboard/pending-approvals')
            ->assertOk()
            ->assertJsonCount(0, 'awaiting_you.payments')
            ->assertJsonCount(0, 'awaiting_client.contracts');
    }

    public function test_users_directory_is_super_admin_only_data(): void
    {
        Sanctum::actingAs($this->outsider);
        $this->getJson('/api/users')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $this->outsider->id);

        Sanctum::actingAs($this->superAdmin);
        $this->getJson('/api/users')->assertOk()->assertJsonCount(3);
    }
}
