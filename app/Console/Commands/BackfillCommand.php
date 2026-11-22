<?php

namespace App\Console\Commands;

use App\Aodb\AodbClient;
use App\Aodb\AodbResponse;
use App\Collector\Backfiller;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time backfill at go-live (D-046), run by hand after the first ok poll. Re-run to resume:
 * days already backfilled are skipped.
 */
class BackfillCommand extends Command
{
    protected $signature = 'hub:backfill {--days=30 : How many Kigali days back from today (1–90)}';

    protected $description = 'Backfill past days once (final state only, source backfill; D-046)';

    public function handle(): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 90]]);
        if ($days === false) {
            $this->getOutput()->getErrorStyle()->writeln('hub:backfill: --days must be a whole number from 1 to 90.');

            return self::FAILURE;
        }
        if (! DB::table('polls')->where('outcome', AodbResponse::OK)->exists()) {
            $this->getOutput()->getErrorStyle()->writeln('hub:backfill: no ok poll yet. Run hub:poll first: the backfill fills only the days before the poll window.');

            return self::FAILURE;
        }

        $now = CarbonImmutable::now('UTC');
        $list = Backfiller::days($days, $now);
        if ($list === []) {
            $this->line('hub:backfill: no day before the poll window in that range; nothing to do.');

            return self::SUCCESS;
        }
        $this->line('hub:backfill: '.count($list).' days, '.$list[0].' to '.end($list).' (Kigali), oldest first.');

        $result = (new Backfiller(app(AodbClient::class), report: fn (string $line) => $this->line('hub:backfill: '.$line)))->run($days, $now);

        $summary = "{$result['written']} days written, {$result['skipped']} skipped (already backfilled), {$result['new']} flights added, {$result['known']} already known (untouched)";
        if ($result['stopped'] !== null) {
            $this->getOutput()->getErrorStyle()->writeln("hub:backfill: STOPPED at {$result['stopped']}. Nothing more was written. {$summary}.");
            $this->getOutput()->getErrorStyle()->writeln('hub:backfill: re-run hub:backfill to resume once the cause is fixed (written days are skipped).');

            return self::FAILURE;
        }
        $this->line("hub:backfill: done. {$summary}.");

        return self::SUCCESS;
    }
}
