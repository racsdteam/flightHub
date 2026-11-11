<?php

namespace Tests\Feature\Collector;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use Tests\TestCase;

class RetentionAndScheduleTest extends TestCase
{
    private function raw(int $ageDays): int
    {
        $body = gzencode('{"flights":[]}');

        return DB::table('raw_responses')->insertGetId([
            'received_at' => DB::raw("UTC_TIMESTAMP(6) - INTERVAL {$ageDays} DAY"),
            'sha256' => str_repeat('0', 64),
            'body' => $body,
            'bytes_raw' => 14,
            'bytes_compressed' => strlen($body),
            'http_status' => 200,
            'endpoint' => '/flights/v1/airport',
        ]);
    }

    public function test_prune_raw_removes_91_day_old_and_keeps_89_day_old(): void
    {
        $old = $this->raw(91);
        $recent = $this->raw(89);

        $this->artisan('hub:prune-raw')
            ->expectsOutputToContain('1 raw responses older than 90 days deleted')
            ->assertExitCode(0);

        $this->assertSame([$recent], DB::table('raw_responses')->pluck('id')->map(fn ($id) => (int) $id)->all());
        $this->assertNotSame($old, $recent);
    }

    public function test_poll_and_prune_are_scheduled_as_specified(): void
    {
        $events = collect(app(Schedule::class)->events())->keyBy(fn (Event $e) => trim(substr($e->command, strpos($e->command, 'artisan') + 8), " '"));

        $poll = $events['hub:poll'];
        $this->assertSame('*/5 * * * *', $poll->expression);
        $this->assertTrue($poll->withoutOverlapping);
        $this->assertSame(5, $poll->expiresAt);

        $this->assertSame('0 0 * * *', $events['hub:prune-raw']->expression);
    }

    public function test_poll_cron_comes_from_config(): void
    {
        config(['aodb.poll_cron' => '*/2 * * * *']);
        $this->refreshApplicationKeepingConfig();

        $poll = collect(app(Schedule::class)->events())->first(fn (Event $e) => str_contains($e->command, 'hub:poll'));
        $this->assertSame('*/2 * * * *', $poll->expression);
    }

    /**
     * routes/console.php is read when the console kernel boots: reload it
     * with the changed config.
     */
    private function refreshApplicationKeepingConfig(): void
    {
        $this->app->forgetInstance(Schedule::class);
        $this->app->instance(Schedule::class, new Schedule);
        ScheduleFacade::clearResolvedInstance(Schedule::class);
        require base_path('routes/console.php');
    }
}
