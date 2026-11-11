<?php

namespace App\Console\Commands;

use App\Api\ApiKeys;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class KeyIssueCommand extends Command
{
    protected $signature = 'hub:key-issue
        {consumer : Consumer code}
        {--label= : What the key is for}
        {--expires= : Expiry date or date-time (UTC), optional}
        {--by= : Who issues it (free text until admin accounts exist)}';

    protected $description = 'Issue an API key: printed once on stdout, stored only as its hash';

    public function handle(): int
    {
        $consumer = DB::table('consumers')->where('code', (string) $this->argument('consumer'))->first();
        $label = trim((string) $this->option('label'));
        $by = trim((string) $this->option('by'));
        $expires = null;
        $problems = [];

        if ($consumer === null) {
            $problems[] = 'consumer not found';
        }
        if ($label === '' || mb_strlen($label) > 100) {
            $problems[] = '--label is required (at most 100 characters)';
        }
        if ($by === '' || mb_strlen($by) > 100) {
            $problems[] = '--by is required (at most 100 characters)';
        }
        if ($this->option('expires') !== null) {
            try {
                $expires = CarbonImmutable::parse((string) $this->option('expires'), 'UTC');
                if ($expires->lte(CarbonImmutable::now('UTC'))) {
                    $problems[] = '--expires must be in the future';
                }
            } catch (Throwable) {
                $problems[] = '--expires must be a date or date-time';
            }
        }

        if ($problems !== []) {
            foreach ($problems as $problem) {
                $this->getOutput()->getErrorStyle()->writeln("hub:key-issue: {$problem}");
            }

            return self::FAILURE;
        }

        $issued = ApiKeys::issue((int) $consumer->id, $label, $expires, $by);

        $this->getOutput()->getErrorStyle()->writeln("hub:key-issue: key {$issued['prefix']} for {$consumer->code}; shown once, stored only as its hash:");
        // The only place the full key ever appears.
        $this->line($issued['key']);

        return self::SUCCESS;
    }
}
