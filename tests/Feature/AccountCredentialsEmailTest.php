<?php

namespace Tests\Feature;

use App\Mail\AccountCredentialsMail;
use App\Models\Client;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The login details of an account created *for* someone else are emailed to
 * them: a manager (by the super admin), an assistant (by the manager), a
 * sub-user (by the client). On by default, `send_email=false` opts out.
 */
class AccountCredentialsEmailTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->superAdmin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $this->manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER, 'super_admin_id' => $this->superAdmin->id]);
    }

    private function assertSentTo(string $email, string $password): void
    {
        Mail::assertSent(AccountCredentialsMail::class, fn (AccountCredentialsMail $m) => $m->hasTo($email) && $m->password === $password && $m->email === $email);
    }

    public function test_a_new_manager_gets_their_credentials_by_email(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $this->postJson('/api/account-managers', ['name' => 'Mona', 'email' => 'mona@example.com', 'password' => 'Password123'])->assertCreated();

        $this->assertSentTo('mona@example.com', 'Password123');
    }

    public function test_a_manager_created_with_a_generated_password_gets_that_password(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $response = $this->postJson('/api/account-managers', ['name' => 'Mona', 'email' => 'mona@example.com'])->assertCreated();

        $this->assertSentTo('mona@example.com', $response->json('credentials.password'));
    }

    public function test_the_manager_email_can_be_switched_off(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $this->postJson('/api/account-managers', ['name' => 'Mona', 'email' => 'mona@example.com', 'password' => 'Password123', 'send_email' => false])->assertCreated();

        Mail::assertNothingSent();
    }

    public function test_a_new_assistant_gets_their_credentials_by_email(): void
    {
        Sanctum::actingAs($this->manager);

        $this->postJson('/api/team', ['name' => 'Sami', 'email' => 'sami@example.com', 'password' => 'Password123'])->assertCreated();

        $this->assertSentTo('sami@example.com', 'Password123');
    }

    public function test_the_assistant_email_can_be_switched_off(): void
    {
        Sanctum::actingAs($this->manager);

        $this->postJson('/api/team', ['name' => 'Sami', 'email' => 'sami@example.com', 'password' => 'Password123', 'send_email' => false])->assertCreated();

        Mail::assertNothingSent();
    }

    public function test_a_failed_validation_sends_nothing(): void
    {
        Sanctum::actingAs($this->manager);

        $this->postJson('/api/team', ['name' => 'Sami', 'email' => 'sami@example.com', 'password' => 'short'])->assertUnprocessable();

        Mail::assertNothingSent();
    }

    public function test_changing_an_assistants_password_emails_it_only_when_asked(): void
    {
        $assistant = User::factory()->create([
            'role' => User::ROLE_MANAGER_ASSISTANT,
            'parent_manager_id' => $this->manager->id,
            'assistant_permissions' => ['can_view_clients' => true],
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->manager);

        $this->patchJson("/api/team/{$assistant->id}/password", ['password' => 'NewPassword1'])->assertOk();
        Mail::assertNothingSent();

        $this->patchJson("/api/team/{$assistant->id}/password", ['password' => 'NewPassword2', 'send_email' => true])->assertOk();
        $this->assertSentTo($assistant->email, 'NewPassword2');
    }

    public function test_a_new_sub_user_gets_their_credentials_by_email(): void
    {
        $client = Client::factory()->create(['manager_id' => $this->manager->id]);
        Workspace::factory()->create(['client_id' => $client->id, 'manager_id' => $this->manager->id]);

        $this->actingAs($client, 'client')
            ->postJson("/api/clients/{$client->id}/sub-users", ['name' => 'Acc', 'email' => 'acc@example.com', 'password' => 'Password1'])
            ->assertCreated();

        $this->assertSentTo('acc@example.com', 'Password1');
    }

    public function test_the_sub_user_email_can_be_switched_off(): void
    {
        $client = Client::factory()->create(['manager_id' => $this->manager->id]);
        Workspace::factory()->create(['client_id' => $client->id, 'manager_id' => $this->manager->id]);

        $this->actingAs($client, 'client')
            ->postJson("/api/clients/{$client->id}/sub-users", ['name' => 'Acc', 'email' => 'acc@example.com', 'password' => 'Password1', 'send_email' => false])
            ->assertCreated();

        Mail::assertNothingSent();
    }
}
