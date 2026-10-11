<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Locks in the indexes the hot queries depend on (2026_10_11_000001), so a
 * later migration can't silently drop one.
 */
class PerformanceIndexesTest extends TestCase
{
    use RefreshDatabase;

    public static function indexes(): array
    {
        return [
            'payments status+due_date' => ['payments', 'payments_status_due_date_index'],
            'payments status+created_at' => ['payments', 'payments_status_created_at_index'],
            'contracts status+created_at' => ['contracts', 'contracts_status_created_at_index'],
            'approvals status+created_at' => ['approvals', 'approvals_status_created_at_index'],
            'meetings status+scheduled_at' => ['meetings', 'meetings_status_scheduled_at_index'],
            'chat workspace+created_at' => ['chat_messages', 'chat_messages_workspace_id_created_at_index'],
            'audit created_at' => ['audit_logs', 'audit_logs_created_at_index'],
            'notifications per user' => ['notifications', 'notifications_notifiable_created_at_index'],
        ];
    }

    #[DataProvider('indexes')]
    public function test_the_index_exists(string $table, string $index): void
    {
        $this->assertTrue(Schema::hasIndex($table, $index), "Missing index {$index} on {$table}");
    }

    public function test_the_migration_can_be_rolled_back_and_re_run(): void
    {
        $migration = require database_path('migrations/2026_10_11_000001_add_performance_indexes.php');

        $migration->down();
        $this->assertFalse(Schema::hasIndex('payments', 'payments_status_due_date_index'));

        $migration->up();
        $migration->up(); // a second run must be a no-op, not an error
        $this->assertTrue(Schema::hasIndex('payments', 'payments_status_due_date_index'));
    }
}
