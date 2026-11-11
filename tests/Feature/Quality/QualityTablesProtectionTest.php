<?php

namespace Tests\Feature\Quality;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * E4: quality_flags is never deleted (grants); quality_flag_events is
 * append-only (grants AND triggers).
 */
class QualityTablesProtectionTest extends TestCase
{
    protected $connectionsToTransact = ['mariadb', 'mariadb_ddl'];

    /**
     * @return array{flag: int, event: int}
     */
    private function seedFlag(Connection $db): array
    {
        $flight = $db->table('flights')->insertGetId([
            'aodb_id' => 'Q-'.bin2hex(random_bytes(4)),
            'record' => '{}',
            'first_seen_at' => '2026-10-29 05:00:00',
            'last_seen_at' => '2026-10-29 05:00:00',
        ]);
        $flag = $db->table('quality_flags')->insertGetId([
            'flight_id' => $flight,
            'rule' => 'type_missing',
            'flag_key' => 'type_missing||',
            'state' => 'open',
            'first_detected_at' => '2026-10-29 05:00:00',
            'last_detected_at' => '2026-10-29 05:00:00',
        ]);
        $event = $db->table('quality_flag_events')->insertGetId([
            'flag_id' => $flag,
            'kind' => 'detected',
            'to_value' => 'open',
            'at' => '2026-10-29 05:00:00',
        ]);

        return ['flag' => $flag, 'event' => $event];
    }

    private function refused(callable $statement, string $expected): QueryException
    {
        try {
            $statement();
        } catch (QueryException $e) {
            $this->assertStringContainsString($expected, $e->getMessage());

            return $e;
        }
        $this->fail("Expected: {$expected}");
    }

    public function test_runtime_can_insert_and_update_flags_and_insert_events(): void
    {
        $db = DB::connection('mariadb');
        $ids = $this->seedFlag($db);

        $this->assertSame(1, $db->table('quality_flags')->where('id', $ids['flag'])->update(['state' => 'cleared']));
        $this->assertSame(1, $db->table('quality_flag_events')->where('id', $ids['event'])->count());
    }

    public function test_runtime_is_refused_delete_on_quality_flags(): void
    {
        $db = DB::connection('mariadb');
        $ids = $this->seedFlag($db);

        $e = $this->refused(fn () => $db->delete('DELETE FROM quality_flags WHERE id = ?', [$ids['flag']]), 'DELETE command denied');
        $this->assertSame(1142, $e->errorInfo[1]);
    }

    public function test_runtime_is_refused_update_and_delete_on_quality_flag_events(): void
    {
        $db = DB::connection('mariadb');
        $ids = $this->seedFlag($db);

        $this->assertSame(1142, $this->refused(fn () => $db->update('UPDATE quality_flag_events SET note = ? WHERE id = ?', ['x', $ids['event']]), 'UPDATE command denied')->errorInfo[1]);
        $this->assertSame(1142, $this->refused(fn () => $db->delete('DELETE FROM quality_flag_events WHERE id = ?', [$ids['event']]), 'DELETE command denied')->errorInfo[1]);
    }

    public function test_triggers_refuse_update_and_delete_on_quality_flag_events_even_for_ddl(): void
    {
        $db = DB::connection('mariadb_ddl');
        $ids = $this->seedFlag($db);

        foreach (['UPDATE quality_flag_events SET note = \'x\' WHERE id = ?', 'DELETE FROM quality_flag_events WHERE id = ?'] as $sql) {
            $e = $this->refused(fn () => $db->statement($sql, [$ids['event']]), 'quality_flag_events is append-only');
            $this->assertSame('45000', $e->errorInfo[0]);
        }
        $this->assertSame(1, $db->table('quality_flag_events')->where('id', $ids['event'])->count());
    }
}
