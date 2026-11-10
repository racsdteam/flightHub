<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneRawCommand extends Command
{
    protected $signature = 'hub:prune-raw';

    protected $description = 'Delete raw responses older than 90 days (D-008; the trigger enforces it too)';

    public function handle(): int
    {
        $deleted = 0;
        do {
            $batch = DB::delete(
                'DELETE FROM raw_responses WHERE received_at < UTC_TIMESTAMP() - INTERVAL 90 DAY ORDER BY id LIMIT 1000'
            );
            $deleted += $batch;
        } while ($batch === 1000);

        $this->line("hub:prune-raw: {$deleted} raw responses older than 90 days deleted.");

        return self::SUCCESS;
    }
}
