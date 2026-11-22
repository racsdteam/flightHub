<?php

namespace App\Console\Commands;

use App\Admin\ReapplyPending;
use App\Collector\CorrectionReapplier;
use Illuminate\Console\Command;
use RuntimeException;

class ReapplyCorrectionsCommand extends Command
{
    protected $signature = 'hub:reapply-corrections
        {--if-pending : Run only when a reference-data change marked a re-apply as pending (scheduled)}';

    protected $description = 'Re-evaluate served values against the active reference data, then the quality rules (run after reference data changed)';

    public function handle(): int
    {
        if ($this->option('if-pending') && ! ReapplyPending::isPending()) {
            $this->line('hub:reapply-corrections: nothing pending.');

            return self::SUCCESS;
        }

        // Cleared before the run: a change made during it marks a new run.
        ReapplyPending::clear();

        try {
            $result = (new CorrectionReapplier)->run();
        } catch (RuntimeException $e) {
            ReapplyPending::mark();
            $this->getOutput()->getErrorStyle()->writeln('hub:reapply-corrections: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($result['retry']) {
            ReapplyPending::mark();
        }
        $quality = $result['quality'] === null
            ? "quality rules not run ({$result['quality_skipped']})"
            : "quality: {$result['quality']['evaluated']} flights evaluated, {$result['quality']['detected']} flags detected, {$result['quality']['cleared']} cleared, {$result['quality']['reopened']} reopened";
        $this->line("hub:reapply-corrections: {$result['flights']} flights corrected, {$result['events']} correction events; {$quality}.");

        return self::SUCCESS;
    }
}
