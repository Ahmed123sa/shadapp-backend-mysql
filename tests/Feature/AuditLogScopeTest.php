<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A manager's audit view must never match rows by auditable_id alone: ids
 * from different tables collide (client 5 / approval 5 / user 5).
 */
class AuditLogScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private User $manager;
    private User $otherManager;
    private Client $myClient;
    private Client $otherClient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $this->manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER, 'super_admin_id' => $this->superAdmin->id, 'is_active' => true]);
        $this->otherManager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER, 'super_admin_id' => $this->superAdmin->id, 'is_active' => true]);
        $this->myClient = Client::factory()->create(['manager_id' => $this->manager->id]);
        $this->otherClient = Client::factory()->create(['manager_id' => $this->otherManager->id]);
    }

    private function log(string $type, int $id, array $extra = []): AuditLog
    {
        return AuditLog::create(array_merge([
            'auditable_type' => $type,
            'auditable_id' => $id,
            'action' => 'test.action',
            'ip_address' => '1.1.1.1',
        ], $extra));
    }

    public function test_a_row_of_another_type_whose_id_equals_my_clients_id_is_not_shown(): void
    {
        // Same numeric id as my client, but it is an Approval row that belongs
        // to the other manager's client.
        $collision = $this->log('App\\Models\\Approval', $this->myClient->id, [
            'client_id' => $this->otherClient->id,
            'user_id' => $this->otherManager->id,
            'action' => 'approval.created',
        ]);

        Sanctum::actingAs($this->manager);
        $ids = collect($this->getJson('/api/audit-logs')->assertOk()->json('logs.data'))->pluck('id');

        $this->assertFalse($ids->contains($collision->id), 'a colliding auditable_id of another type must not leak');
    }

    public function test_rows_about_my_own_client_record_are_still_shown(): void
    {
        $own = $this->log(Client::class, $this->myClient->id, ['user_id' => $this->superAdmin->id, 'action' => 'client.updated']);
        $other = $this->log(Client::class, $this->otherClient->id, ['user_id' => $this->superAdmin->id, 'action' => 'client.updated']);

        Sanctum::actingAs($this->manager);
        $ids = collect($this->getJson('/api/audit-logs')->assertOk()->json('logs.data'))->pluck('id');

        $this->assertTrue($ids->contains($own->id));
        $this->assertFalse($ids->contains($other->id));
    }

    public function test_rows_carrying_my_client_id_are_still_shown(): void
    {
        $row = $this->log('App\\Models\\Approval', 999, ['client_id' => $this->myClient->id, 'user_id' => $this->superAdmin->id]);

        Sanctum::actingAs($this->manager);
        $ids = collect($this->getJson('/api/audit-logs')->assertOk()->json('logs.data'))->pluck('id');

        $this->assertTrue($ids->contains($row->id));
    }

    public function test_the_dashboard_login_count_ignores_colliding_ids_too(): void
    {
        $this->log(User::class, $this->myClient->id, ['user_id' => $this->otherManager->id, 'action' => 'login']);

        Sanctum::actingAs($this->manager);
        $this->getJson('/api/reports')->assertOk()->assertJsonPath('recent_logins', 0);
    }
}
