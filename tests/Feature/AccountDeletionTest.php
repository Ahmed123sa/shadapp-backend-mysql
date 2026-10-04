<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\Client;
use App\Models\MobileNotificationToken;
use App\Models\SubUser;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\ManagerAccountDeletedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * DELETE /api/auth/account (App Store 5.1.1(v)) — see AccountDeletionService.
 */
class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function makeClientWorld(): array
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER, 'is_active' => true]);
        $client = Client::factory()->create([
            'manager_id' => $manager->id,
            'email' => 'client@test.com',
            'password' => 'Password1',
            'phone' => '0100000000',
        ]);
        $workspace = Workspace::factory()->create(['client_id' => $client->id, 'manager_id' => $manager->id]);

        return compact('manager', 'client', 'workspace');
    }

    private function deleteAs(object $account, ?string $password = 'Password1')
    {
        $token = $account->createToken('t')->plainTextToken;

        return $this->withToken($token)->deleteJson('/api/auth/account', $password === null ? [] : ['password' => $password]);
    }

    public function test_client_deletion_anonymises_and_removes_sub_users(): void
    {
        ['client' => $client, 'workspace' => $workspace, 'manager' => $manager] = $this->makeClientWorld();
        $sub = SubUser::factory()->create(['client_id' => $client->id]);
        $sub->createToken('s');
        MobileNotificationToken::create(['token' => 'fcm-c', 'device_type' => 'android', 'tokenable_id' => $client->id, 'tokenable_type' => Client::class]);
        MobileNotificationToken::create(['token' => 'fcm-s', 'device_type' => 'android', 'tokenable_id' => $sub->id, 'tokenable_type' => SubUser::class]);
        ChatMessage::create(['workspace_id' => $workspace->id, 'sender_type' => Client::class, 'sender_id' => $client->id, 'message' => 'hi', 'type' => 'text']);
        ChatMessage::create(['workspace_id' => $workspace->id, 'sender_type' => User::class, 'sender_id' => $manager->id, 'message' => 'reply', 'type' => 'text']);

        $this->deleteAs($client)->assertOk();

        $client->refresh();
        $this->assertSame('deleted', $client->status);
        $this->assertSame('', $client->phone);
        $this->assertStringEndsWith('@deleted.invalid', $client->email);
        $this->assertNotSame('client@test.com', $client->email);
        $this->assertNull($client->signature_data);

        $this->assertDatabaseMissing('sub_users', ['id' => $sub->id]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('mobile_notification_tokens', 0);
        // The client's chat message is gone; the manager's stays.
        $this->assertDatabaseCount('chat_messages', 1);
        $this->assertDatabaseHas('chat_messages', ['sender_id' => $manager->id, 'sender_type' => User::class]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.deleted', 'client_id' => $client->id]);
        // Row kept so contracts/payments stay attached.
        $this->assertDatabaseHas('clients', ['id' => $client->id]);
    }

    public function test_deleted_client_cannot_log_in_and_workspace_is_frozen(): void
    {
        ['client' => $client, 'workspace' => $workspace] = $this->makeClientWorld();

        $this->deleteAs($client)->assertOk();

        $this->postJson('/api/auth/login', ['email' => 'client@test.com', 'password' => 'Password1'])->assertStatus(422);
        $this->assertTrue($workspace->fresh()->isClientArchived());
    }

    public function test_sub_user_deletion_leaves_the_client_untouched(): void
    {
        ['client' => $client] = $this->makeClientWorld();
        $sub = SubUser::factory()->create(['client_id' => $client->id]);

        $this->deleteAs($sub)->assertOk();

        $this->assertDatabaseMissing('sub_users', ['id' => $sub->id]);
        $this->assertSame('active', $client->fresh()->status);
    }

    public function test_account_manager_deletion_scrubs_and_notifies_super_admins(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        ['manager' => $manager, 'client' => $client] = $this->makeClientWorld();
        $manager->update(['password' => 'Password1']);

        $this->deleteAs($manager)->assertOk();

        $manager->refresh();
        $this->assertFalse((bool) $manager->is_active);
        $this->assertStringEndsWith('@deleted.invalid', $manager->email);
        $this->assertSame('Deleted account', $manager->name);
        // Clients are left for the admin to transfer.
        $this->assertSame($manager->id, $client->fresh()->manager_id);
        Notification::assertSentTo($admin, ManagerAccountDeletedNotification::class);
    }

    public function test_manager_without_clients_does_not_notify_admins(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER, 'is_active' => true, 'password' => 'Password1']);

        $this->deleteAs($manager)->assertOk();

        Notification::assertNothingSentTo($admin);
    }

    public function test_super_admin_cannot_delete_account(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN, 'password' => 'Password1']);

        $this->deleteAs($admin)->assertForbidden()->assertJsonStructure(['message']);

        $this->assertStringNotContainsString('deleted.invalid', $admin->fresh()->email);
    }

    public function test_wrong_or_missing_password_is_422_never_401(): void
    {
        ['client' => $client] = $this->makeClientWorld();

        $this->deleteAs($client, 'nope')->assertStatus(422)->assertJsonValidationErrors('password');
        $this->deleteAs($client, null)->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertSame('active', $client->fresh()->status);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->deleteJson('/api/auth/account', ['password' => 'x'])->assertUnauthorized();
    }

    public function test_feature_flag_off_returns_403_and_changes_nothing(): void
    {
        config(['account_deletion.enabled' => false]);
        ['client' => $client] = $this->makeClientWorld();

        $this->deleteAs($client)->assertForbidden()->assertJsonStructure(['message']);

        $this->assertSame('active', $client->fresh()->status);
    }

    public function test_deleting_one_account_does_not_touch_another_client(): void
    {
        ['client' => $client] = $this->makeClientWorld();
        $other = Client::factory()->create(['email' => 'other@test.com']);
        $other->createToken('o');

        $this->deleteAs($client)->assertOk();

        $this->assertSame('active', $other->fresh()->status);
        $this->assertSame('other@test.com', $other->fresh()->email);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }
}
