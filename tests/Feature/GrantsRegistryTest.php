<?php

namespace Tests\Feature;

use App\Database\Grants\GrantRegistry;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GrantsRegistryTest extends TestCase
{
    private const APPEND_ONLY = ['polls', 'raw_responses', 'flight_versions', 'change_events'];

    private function hubTestDatabase(): string
    {
        return DB::connection('mariadb_ddl')->getDatabaseName();
    }

    /**
     * Locally one runtime user holds the dev and the test database (apply-grants.sh).
     */
    private function runtimeDatabases(): array
    {
        $test = $this->hubTestDatabase();

        return [$test, substr($test, 0, -strlen('_test'))];
    }

    public function test_every_table_is_in_the_registry_and_vice_versa(): void
    {
        // Read as the DDL user (D-014).
        $tables = array_map(
            fn ($row) => $row->TABLE_NAME,
            DB::connection('mariadb_ddl')->select(
                "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'",
                [$this->hubTestDatabase()],
            ),
        );

        $registry = GrantRegistry::fromConfig()->tables();
        sort($registry);
        sort($tables);

        $this->assertSame($registry, $tables);
    }

    public function test_every_append_only_table_has_both_triggers(): void
    {
        $triggers = DB::connection('mariadb_ddl')->select(
            'SELECT EVENT_OBJECT_TABLE AS t, EVENT_MANIPULATION AS e FROM information_schema.TRIGGERS
             WHERE TRIGGER_SCHEMA = ? AND ACTION_TIMING = ?',
            [$this->hubTestDatabase(), 'BEFORE'],
        );
        $have = array_map(fn ($row) => "{$row->t}:{$row->e}", $triggers);

        foreach (self::APPEND_ONLY as $table) {
            $this->assertContains("{$table}:UPDATE", $have);
            $this->assertContains("{$table}:DELETE", $have);
        }
    }

    public function test_check_grants_passes_on_the_test_database(): void
    {
        $this->artisan('hub:check-grants', ['--database' => $this->runtimeDatabases()])
            ->expectsOutputToContain('runtime grants match config/grants.php')
            ->doesntExpectOutputToContain('IDENTIFIED')
            ->assertExitCode(0);
    }

    public function test_check_grants_fails_when_the_registry_differs(): void
    {
        config(['grants.tables.polls' => ['SELECT', 'INSERT', 'UPDATE']]);

        $this->artisan('hub:check-grants', ['--database' => $this->runtimeDatabases()])
            ->expectsOutputToContain('polls: missing UPDATE')
            ->doesntExpectOutputToContain('IDENTIFIED')
            ->assertExitCode(1);
    }

    public function test_grants_sql_prints_revoke_then_per_table_grants(): void
    {
        $db = $this->hubTestDatabase();

        $this->artisan('hub:grants-sql', ['--database' => [$db], '--host' => 'localhost'])
            ->expectsOutputToContain("REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'flighthub_app'@'localhost';")
            ->expectsOutputToContain("GRANT INSERT, SELECT ON `{$db}`.`polls` TO 'flighthub_app'@'localhost';")
            ->expectsOutputToContain("GRANT INSERT, SELECT, UPDATE ON `{$db}`.`flights` TO 'flighthub_app'@'localhost';")
            ->assertExitCode(0);
    }

    public function test_grants_sql_fails_when_a_table_is_missing_from_the_registry(): void
    {
        $tables = config('grants.tables');
        unset($tables['change_events']);
        config(['grants.tables' => $tables]);

        $this->artisan('hub:grants-sql', ['--database' => [$this->hubTestDatabase()]])
            ->expectsOutputToContain('change_events exists but is not in config/grants.php')
            ->doesntExpectOutputToContain('REVOKE')
            ->assertExitCode(1);
    }

    public function test_grants_sql_fails_when_a_registry_table_does_not_exist(): void
    {
        config(['grants.tables.not_a_table' => ['SELECT']]);

        $this->artisan('hub:grants-sql', ['--database' => [$this->hubTestDatabase()]])
            ->expectsOutputToContain('not_a_table is in config/grants.php but does not exist')
            ->assertExitCode(1);
    }
}
