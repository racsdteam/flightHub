<?php

namespace App\Console\Commands;

use App\Aodb\AodbClient;
use App\Collector\Poller;
use Illuminate\Console\Command;

class PollCommand extends Command
{
    protected $signature = 'hub:poll';

    protected $description = 'Poll the KIA AODB once and record every change (runtime user)';

    public function handle(): int
    {
        $poll = (new Poller(fn () => app(AodbClient::class)))->run();

        $this->line(sprintf(
            'hub:poll: %s; %s flights in payload, %d new, %d changed, %d removed, %d returned, %d events%s',
            $poll['outcome'],
            $poll['flights_in_payload'] ?? '-',
            $poll['flights_new'],
            $poll['flights_changed'],
            $poll['flights_removed'],
            $poll['flights_returned'],
            $poll['events_written'],
            $poll['content_changed'] ? '' : ' (content unchanged)',
        ));

        if (isset($poll['quality'])) {
            $q = $poll['quality'];
            $this->line("hub:poll: quality: {$q['evaluated']} flights evaluated, {$q['detected']} detected, {$q['reopened']} reopened, {$q['cleared']} cleared");
        } elseif (isset($poll['quality_skipped'])) {
            $this->line("hub:poll: quality rules not run ({$poll['quality_skipped']})");
        }

        // Every handled outcome exits 0 (cron must not mail every minute); it lives in polls.
        return self::SUCCESS;
    }
}
