<?php

namespace App\Console\Commands;

use App\Database\Grants\GrantRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GrantsSqlCommand extends Command
{
    protected $signature = 'hub:grants-sql
        {--database=* : Database to grant on (repeatable)}
        {--host= : Host part of the runtime account (default DB_GRANT_HOST, else localhost)}';

    protected $description = 'Print the SQL (for root) that gives the runtime user its per-table grants';

    public function handle(): int
    {
        $registry = GrantRegistry::fromConfig();
        $databases = array_values(array_unique($this->option('database')));
        $user = (string) config('database.connections.mariadb.username');
        $host = (string) ($this->option('host') ?: config('grants.host'));

        $problems = [];
        if ($databases === []) {
            $problems[] = 'give at least one --database';
        }
        if (! preg_match('/^[A-Za-z0-9_]{1,80}$/', $user)) {
            $problems[] = 'the runtime username (DB_USERNAME) is empty or invalid';
        }
        if (! preg_match('/^[A-Za-z0-9._%:-]{1,255}$/', $host)) {
            $problems[] = 'invalid --host';
        }
        foreach ($databases as $database) {
            if (! preg_match('/^[A-Za-z0-9_]{1,64}$/', $database)) {
                $problems[] = 'invalid --database';

                continue;
            }
            // Read as the DDL user (D-014): the runtime user cannot see new tables.
            $existing = array_map(
                fn ($row) => $row->TABLE_NAME,
                DB::connection('mariadb_ddl')->select(
                    "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'",
                    [$database],
                ),
            );
            $problems = array_merge($problems, $registry->tableProblems($database, $existing));
        }

        if ($problems !== []) {
            foreach ($problems as $problem) {
                $this->getOutput()->getErrorStyle()->writeln("hub:grants-sql: {$problem}");
            }

            return self::FAILURE;
        }

        $account = "'{$user}'@'{$host}'";
        $this->line('-- Runtime user grants from config/grants.php (hub:grants-sql). Run as root.');
        $this->line("REVOKE ALL PRIVILEGES, GRANT OPTION FROM {$account};");
        foreach ($databases as $database) {
            foreach ($registry->tables() as $table) {
                $privileges = $registry->privilegesFor($table);
                if ($privileges === []) {
                    $this->line("-- {$database}.{$table}: no privileges");

                    continue;
                }
                $this->line('GRANT '.implode(', ', $privileges)." ON `{$database}`.`{$table}` TO {$account};");
            }
        }

        return self::SUCCESS;
    }
}
