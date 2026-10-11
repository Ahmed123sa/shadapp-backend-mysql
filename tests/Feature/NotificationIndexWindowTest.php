<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /notifications no longer loads every notification a user ever had
 * (11 Oct 2026, performance). These pin the result down so it stays exactly
 * what the old load-everything version returned.
 */
class NotificationIndexWindowTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private Workspace $mine;
    private Workspace $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);
        $otherManager = User::factory()->create(['role' => User::ROLE_ACCOUNT_MANAGER]);

        $client = Client::factory()->create(['manager_id' => $this->manager->id]);
        $this->mine = Workspace::factory()->create(['client_id' => $client->id, 'manager_id' => $this->manager->id]);

        $otherClient = Client::factory()->create(['manager_id' => $otherManager->id]);
        $this->other = Workspace::factory()->create(['client_id' => $otherClient->id, 'manager_id' => $otherManager->id]);
    }

    private function note(Workspace $ws, int $minutesAgo, bool $read, string $label = ''): void
    {
        $this->manager->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\Test',
            'data' => ['type' => 'contract_sent', 'workspace_id' => $ws->id, 'message' => $label],
            'read_at' => $read ? now()->subMinutes($minutesAgo) : null,
            'created_at' => now()->subMinutes($minutesAgo),
            'updated_at' => now()->subMinutes($minutesAgo),
        ]);
    }

    public function test_the_list_is_the_newest_fifty_and_the_count_covers_every_unread(): void
    {
        for ($i = 1; $i <= 70; $i++) {
            $this->note($this->mine, $i, read: $i % 2 === 0, label: "n{$i}");
        }
        Sanctum::actingAs($this->manager);

        $res = $this->getJson('/api/notifications')->assertOk();

        $this->assertCount(50, $res->json('notifications'));
        $this->assertSame('n1', $res->json('notifications.0.data.message'));     // newest first
        $this->assertSame('n50', $res->json('notifications.49.data.message'));
        $this->assertSame(35, $res->json('unread_count'));                       // all unread, not just the 50 shown
        $this->assertSame(1, $res->json('unread_clients_count'));
    }

    public function test_hidden_notifications_do_not_crowd_out_visible_older_ones(): void
    {
        // 150 newer read notifications about someone else's client (filtered
        // out), then 10 older read ones about mine — the visible ones are
        // deeper than the first page of read rows and must still be found.
        for ($i = 1; $i <= 150; $i++) {
            $this->note($this->other, $i, read: true);
        }
        for ($i = 151; $i <= 160; $i++) {
            $this->note($this->mine, $i, read: true, label: "mine{$i}");
        }
        Sanctum::actingAs($this->manager);

        $res = $this->getJson('/api/notifications')->assertOk();

        $this->assertCount(10, $res->json('notifications'));
        $this->assertSame('mine151', $res->json('notifications.0.data.message'));
        $this->assertSame(0, $res->json('unread_count'));
    }

    public function test_unread_about_other_managers_clients_is_not_counted(): void
    {
        $this->note($this->mine, 1, read: false);
        $this->note($this->other, 2, read: false);
        $this->note($this->other, 3, read: false);
        Sanctum::actingAs($this->manager);

        $this->getJson('/api/notifications')->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonCount(1, 'notifications');
    }

    public function test_an_empty_inbox_keeps_the_same_shape(): void
    {
        Sanctum::actingAs($this->manager);

        $this->getJson('/api/notifications')->assertOk()
            ->assertExactJson(['notifications' => [], 'unread_count' => 0, 'unread_clients_count' => 0]);
    }
}
