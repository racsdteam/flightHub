<?php

namespace App\Console\Commands;

use App\Database\Grants\GrantComparator;
use App\Database\Grants\GrantRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckGrantsCommand extends Command
{
    protected $signature = 'hub:check-grants
        {--database=* : Database the runtime user must hold (repeatable; default: the runtime connection\'s database)}';

    protected $description = "Verify the runtime user's real privileges against config/grants.php";

    public function handle(): int
    {
        $connection = DB::connection('mariadb');
        $databases = array_values(array_unique($this->option('database') ?: [$connection->getDatabaseName()]));

        // Raw grant lines contain password hashes: they are parsed, never printed.
        $lines = array_map(
            fn ($row) => (string) array_values((array) $row)[0],
            $connection->select('SHOW GRANTS FOR CURRENT_USER()'),
        );

        $problems = GrantComparator::compare(GrantRegistry::fromConfig(), $databases, $lines);

        if ($problems !== []) {
            foreach ($problems as $problem) {
                $this->getOutput()->getErrorStyle()->writeln("hub:check-grants: {$problem}");
            }

            return self::FAILURE;
        }

        $this->line('hub:check-grants: runtime grants match config/grants.php for '.implode(', ', $databases).'.');

        return self::SUCCESS;
    }
}
