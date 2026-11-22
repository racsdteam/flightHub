<?php

namespace App\Console\Commands;

use App\Deploy\Preflight;
use App\Deploy\Probe;
use Illuminate\Console\Command;

/**
 * hub:preflight — the production checks (deploy.sh runs it before artisan up). Prints check
 * names and pass / warn / FAIL / skipped only, never a value; exit 1 on any FAIL.
 */
class PreflightCommand extends Command
{
    protected $signature = 'hub:preflight
        {--ddl-login : Only check that the DDL credentials in this run\'s environment connect (deploy.sh, before maintenance)}
        {--fingerprint : Internal: print the config and route fingerprints of this boot (used to detect a stale cache)}';

    protected $description = 'Check the production posture: names and results only, never a value (exit 1 on any FAIL)';

    public function handle(Probe $probe): int
    {
        if ($this->option('fingerprint')) {
            $this->line(json_encode(['config' => Preflight::configFingerprint(config()->all()), 'routes' => Preflight::routeFingerprint()]));

            return self::SUCCESS;
        }

        if ($this->option('ddl-login')) {
            $ok = $probe->environmentHas('DB_DDL_USERNAME') && $probe->environmentHas('DB_DDL_PASSWORD');
            if ($ok) {
                $probe->useDdlFromEnvironment();
                $ok = $probe->ddlLoginWorks();
            }
            $this->line(sprintf('  %-8s %s', $ok ? Preflight::PASS : Preflight::FAIL, 'DDL login (credentials from this run\'s environment)'));

            return $ok ? self::SUCCESS : self::FAILURE;
        }

        $this->line('hub:preflight: production checks (names and results only; never a value).');
        $counts = [Preflight::FAIL => 0, Preflight::WARN => 0, Preflight::SKIPPED => 0];
        foreach ((new Preflight($probe))->run() as [$status, $name]) {
            $this->line(sprintf('  %-8s %s', $status, $name));
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
        }
        $this->line("hub:preflight: {$counts[Preflight::FAIL]} FAIL, {$counts[Preflight::WARN]} warn, {$counts[Preflight::SKIPPED]} skipped.");

        return $counts[Preflight::FAIL] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
