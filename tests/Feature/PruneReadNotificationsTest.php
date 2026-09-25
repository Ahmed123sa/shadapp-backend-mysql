<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\ContractSentNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneReadNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_deletes_read_notifications_older_than_90_days(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $client = Client::factory()->create(['manager_id' => $manager->id]);
        $workspace = Workspace::factory()->create(['client_id' => $client->id, 'manager_id' => $manager->id]);
        $contract = Contract::factory()->create(['workspace_id' => $workspace->id, 'created_by' => $manager->id]);

        // Old and read — should be pruned.
        $manager->notify(new ContractSentNotification($contract));
        $old = $manager->notifications()->latest()->first();
        $old->forceFill(['read_at' => now()->subDays(91)])->save();

        // Read recently — kept, not old enough yet. Notifications use a
        // UUID primary key with no autoincrement to break ties, and both
        // rows can land on the same created_at to the second, so the second
        // row is found by excluding $old's id rather than trusting
        // latest()->first() to pick the newer one a second time.
        $manager->notify(new ContractSentNotification($contract));
        $recent = $manager->notifications()->where('id', '!=', $old->id)->first();
        $recent->forceFill(['read_at' => now()->subDays(10)])->save();

        $this->artisan('notifications:prune');

        $remaining = $manager->fresh()->notifications;
        $this->assertCount(1, $remaining);
        $this->assertTrue($remaining->first()->is($recent));
    }

    public function test_does_not_delete_an_old_unread_notification(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $client = Client::factory()->create(['manager_id' => $manager->id]);
        $workspace = Workspace::factory()->create(['client_id' => $client->id, 'manager_id' => $manager->id]);
        $contract = Contract::factory()->create(['workspace_id' => $workspace->id, 'created_by' => $manager->id]);

        $manager->notify(new ContractSentNotification($contract));
        $unread = $manager->notifications()->latest()->first();
        // Never read (read_at stays null), but old — an unread notification
        // is still actionable regardless of age, so it must survive.
        $unread->forceFill(['created_at' => now()->subDays(200)])->save();

        $this->artisan('notifications:prune');

        $this->assertCount(1, $manager->fresh()->notifications);
    }
}
