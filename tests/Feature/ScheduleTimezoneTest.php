<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 23 Sept 2026 — the times in routes/console.php are Egypt time
 * (config('app.schedule_timezone')), not the app's UTC.
 */
class ScheduleTimezoneTest extends TestCase
{
    private function event(string $command): Event
    {
        foreach (app(Schedule::class)->events() as $event) {
            if (str_contains((string) $event->command, $command)) {
                return $event;
            }
        }
        $this->fail("No scheduled event for {$command}");
    }

    public function test_scheduled_events_use_egypt_time(): void
    {
        $this->assertSame('Africa/Cairo', (string) $this->event('contracts:send-reminders')->timezone);
    }

    public function test_the_nine_oclock_reminders_run_at_nine_in_egypt(): void
    {
        // 1 Dec 2026: Egypt is UTC+2, so 09:00 Egypt is 07:00 UTC.
        Carbon::setTestNow(Carbon::parse('2026-12-01 07:00:00', 'UTC'));
        $this->assertTrue($this->event('payments:send-reminders')->isDue($this->app));

        Carbon::setTestNow(Carbon::parse('2026-12-01 09:00:00', 'UTC'));
        $this->assertFalse($this->event('payments:send-reminders')->isDue($this->app));

        Carbon::setTestNow();
    }
}
