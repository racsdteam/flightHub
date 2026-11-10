<?php

namespace App\Database\Grants;

use InvalidArgumentException;

/**
 * The runtime user's privileges per table (config/grants.php). Deny-by-default:
 * asking for a table that is not registered is an error.
 */
final class GrantRegistry
{
    /** @var array<string, list<string>> */
    private array $tables = [];

    /**
     * @param  array<string, list<string>>  $tables
     */
    public function __construct(array $tables)
    {
        foreach ($tables as $table => $privileges) {
            self::assertIdentifier($table);
            $this->tables[$table] = array_values(array_unique(array_map(
                fn (string $privilege) => strtoupper(trim($privilege)),
                $privileges,
            )));
            sort($this->tables[$table]);
        }
        ksort($this->tables);
    }

    public static function fromConfig(): self
    {
        return new self(config('grants.tables', []));
    }

    /**
     * @return list<string>
     */
    public function tables(): array
    {
        return array_keys($this->tables);
    }

    /**
     * @return list<string> sorted, upper case; empty for "no privileges"
     */
    public function privilegesFor(string $table): array
    {
        if (! array_key_exists($table, $this->tables)) {
            throw new InvalidArgumentException("Table {$table} is not in config/grants.php.");
        }

        return $this->tables[$table];
    }

    /**
     * Differences between the registry and the tables that exist in a database.
     *
     * @param  list<string>  $existing
     * @return list<string>
     */
    public function tableProblems(string $database, array $existing): array
    {
        $problems = [];
        foreach (array_diff($existing, $this->tables()) as $table) {
            $problems[] = "{$database}.{$table} exists but is not in config/grants.php";
        }
        foreach (array_diff($this->tables(), $existing) as $table) {
            $problems[] = "{$database}.{$table} is in config/grants.php but does not exist";
        }

        return array_values($problems);
    }

    public static function assertIdentifier(string $name): void
    {
        if (! preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
            throw new InvalidArgumentException('Invalid identifier in grants configuration.');
        }
    }
}
