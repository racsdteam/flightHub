<?php

namespace Tests\Unit;

use App\Database\Grants\GrantComparator;
use App\Database\Grants\GrantRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GrantComparatorTest extends TestCase
{
    // A FAKE hash: fixture lines look like SHOW GRANTS output, which carries one.
    private const FAKE_HASH = '*0000FAKEHASH0000FAKEHASH0000FAKEHASH0000';

    private const USER = '`flighthub_app`@`127.0.0.1`';

    private static function usage(string $privileges = 'USAGE'): string
    {
        return "GRANT {$privileges} ON *.* TO ".self::USER." IDENTIFIED BY PASSWORD '".self::FAKE_HASH."'";
    }

    private static function table(string $privileges, string $db, string $table, string $suffix = ''): string
    {
        return "GRANT {$privileges} ON `{$db}`.`{$table}` TO ".self::USER.$suffix;
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    private static function compare(array $lines): array
    {
        $registry = new GrantRegistry([
            'polls' => ['SELECT', 'INSERT'],
            'flights' => ['SELECT', 'INSERT', 'UPDATE'],
            'migrations' => [],
        ]);

        return GrantComparator::compare($registry, 'hub_test', $lines);
    }

    /**
     * @return list<string>
     */
    private static function goodLines(): array
    {
        return [
            self::usage(),
            self::table('SELECT, INSERT', 'hub_test', 'polls'),
            self::table('SELECT, INSERT, UPDATE', 'hub_test', 'flights'),
        ];
    }

    public function test_matching_grants_pass(): void
    {
        $this->assertSame([], self::compare(self::goodLines()));
    }

    public static function wrongGrants(): array
    {
        $good = self::goodLines();

        return [
            'extra privilege' => [
                [$good[0], self::table('SELECT, INSERT, UPDATE', 'hub_test', 'polls'), $good[2]],
                'hub_test.polls: extra UPDATE',
            ],
            'database-level grant' => [
                [...$good, 'GRANT SELECT ON `hub_test`.* TO '.self::USER],
                'database-level privileges on hub_test',
            ],
            'grant option' => [
                [$good[0], self::table('SELECT, INSERT', 'hub_test', 'polls', ' WITH GRANT OPTION'), $good[2]],
                'GRANT OPTION',
            ],
            'another database' => [
                [...$good, self::table('SELECT', 'other_db', 'polls')],
                'privileges on another database: other_db',
            ],
            'missing table privileges' => [
                [$good[0], $good[2]],
                'hub_test.polls: missing',
            ],
            'global privilege' => [
                [self::usage('RELOAD'), $good[1], $good[2]],
                'global privileges: RELOAD',
            ],
            'table not in registry' => [
                [...$good, self::table('SELECT', 'hub_test', 'secrets')],
                'hub_test.secrets: privileges on a table not in config/grants.php',
            ],
            'privilege on a no-privilege table' => [
                [...$good, self::table('SELECT', 'hub_test', 'migrations')],
                'hub_test.migrations: extra SELECT',
            ],
            'role grant' => [
                [...$good, 'GRANT `some_role` TO '.self::USER],
                'unrecognised',
            ],
        ];
    }

    #[DataProvider('wrongGrants')]
    public function test_wrong_grants_fail_without_leaking_the_hash(array $lines, string $expected): void
    {
        $problems = self::compare($lines);
        $output = implode("\n", $problems);

        $this->assertNotSame([], $problems);
        $this->assertStringContainsString($expected, $output);
        $this->assertStringNotContainsString(self::FAKE_HASH, $output);
        $this->assertStringNotContainsString('FAKEHASH', $output);
        $this->assertStringNotContainsString('IDENTIFIED', $output);
    }
}
