<?php

namespace App\Database\Grants;

/**
 * Compares the runtime user's real grants (SHOW GRANTS lines) with the
 * registry. Returned problems never contain a raw grant line: SHOW GRANTS
 * output contains password hashes (R7).
 */
final class GrantComparator
{
    private const IDENT = '`((?:[^`]|``)+)`';

    /**
     * @param  string  $database  the one database the runtime user must hold, exactly as the registry says
     * @param  list<string>  $grantLines  SHOW GRANTS FOR CURRENT_USER() output
     * @return list<string> problems; empty when the grants match
     */
    public static function compare(GrantRegistry $registry, string $database, array $grantLines): array
    {
        $problems = [];
        $actual = [];

        foreach (array_values($grantLines) as $i => $line) {
            $n = $i + 1;

            if (! preg_match('/^GRANT (.+?) ON (.+?) TO (.+)$/s', trim($line), $m)) {
                $problems[] = "grant line {$n}: unrecognised (role, proxy or other grant)";

                continue;
            }
            [, $privilegeList, $target, $rest] = $m;

            if (str_contains($rest, 'WITH GRANT OPTION') || str_contains($rest, 'WITH ADMIN OPTION')) {
                $problems[] = "grant line {$n}: GRANT OPTION";
            }

            if (str_contains($privilegeList, '(')) {
                $problems[] = "grant line {$n}: column-level privileges";

                continue;
            }
            $privileges = array_map(fn (string $p) => strtoupper(trim($p)), explode(',', $privilegeList));

            if ($target === '*.*') {
                $extra = array_diff($privileges, ['USAGE']);
                if ($extra !== []) {
                    $problems[] = 'global privileges: '.implode(', ', $extra);
                }
            } elseif (preg_match('/^'.self::IDENT.'\.\*$/', $target, $t)) {
                $problems[] = 'database-level privileges on '.self::unquote($t[1]).': '.implode(', ', $privileges);
            } elseif (preg_match('/^'.self::IDENT.'\.'.self::IDENT.'$/', $target, $t)) {
                $db = self::unquote($t[1]);
                $table = self::unquote($t[2]);
                foreach ($privileges as $privilege) {
                    $actual[$db][$table][$privilege] = true;
                }
            } else {
                $problems[] = "grant line {$n}: privileges on a routine or unrecognised object";
            }
        }

        foreach (array_keys($actual) as $db) {
            if ($db !== $database) {
                $problems[] = "privileges on another database: {$db}";
            }
        }

        $tables = $actual[$database] ?? [];

        foreach (array_keys($tables) as $table) {
            if (! in_array($table, $registry->tables(), true)) {
                $problems[] = "{$database}.{$table}: privileges on a table not in config/grants.php";
            }
        }

        foreach ($registry->tables() as $table) {
            $expected = $registry->privilegesFor($table);
            $have = array_keys($tables[$table] ?? []);

            foreach (array_diff($expected, $have) as $missing) {
                $problems[] = "{$database}.{$table}: missing {$missing}";
            }
            foreach (array_diff($have, $expected) as $extra) {
                $problems[] = "{$database}.{$table}: extra {$extra}";
            }
        }

        return $problems;
    }

    private static function unquote(string $identifier): string
    {
        return str_replace('``', '`', $identifier);
    }
}
