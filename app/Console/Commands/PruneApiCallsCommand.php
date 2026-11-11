<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneApiCallsCommand extends Command
{
    protected $signature = 'hub:prune-api-calls';

    protected $description = 'Delete API call log rows older than 1 year (D-036; the trigger enforces it too)';

    public function handle(): int
    {
        $deleted = 0;
        do {
            $batch = DB::delete('DELETE FROM api_calls WHERE at < UTC_TIMESTAMP() - INTERVAL 365 DAY ORDER BY id LIMIT 1000');
            $deleted += $batch;
        } while ($batch === 1000);

        $this->line("hub:prune-api-calls: {$deleted} API calls older than 1 year deleted.");

        return self::SUCCESS;
    }
}
