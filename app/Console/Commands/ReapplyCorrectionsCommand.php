<?php

namespace App\Console\Commands;

use App\Collector\CorrectionReapplier;
use Illuminate\Console\Command;
use RuntimeException;

class ReapplyCorrectionsCommand extends Command
{
    protected $signature = 'hub:reapply-corrections';

    protected $description = 'Re-evaluate served values against the active reference data (run after reference data changed)';

    public function handle(): int
    {
        try {
            $result = (new CorrectionReapplier)->run();
        } catch (RuntimeException $e) {
            $this->getOutput()->getErrorStyle()->writeln('hub:reapply-corrections: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line("hub:reapply-corrections: {$result['flights']} flights corrected, {$result['events']} correction events.");

        return self::SUCCESS;
    }
}
