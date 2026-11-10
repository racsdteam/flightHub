<?php

namespace Tests\Feature;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Two independent layers protect the history tables (D-013): the runtime
 * user's grants (error 1142) and triggers (SQLSTATE 45000), proven with the
 * DDL connection, which holds the privileges.
 */
class HistoryProtectionTest extends TestCase
{
    // Rows written on either connection are rolled back after each test.
    protected $connectionsToTransact = ['mariadb', 'mariadb_ddl'];

    /**
     * Inserts one row into each history table (and the flight they need).
     *
     * @return array{polls: int, raw_responses: int, flights: int, flight_versions: int, change_events: int}
     */
    private function seedHistory(Connection $db, int $rawAgeDays = 0): array
    {
        $poll = $db->table('polls')->insertGetId([
            'started_at' => '2026-10-29 05:00:00.000000',
            'finished_at' => '2026-10-29 05:00:01.250000',
            'request_window_from' => '2026-10-27 22:00:00',
            'request_window_to' => '2026-10-30 22:00:00',
            'duration_ms' => 1250,
            'outcome' => 'ok',
            'http_status' => 200,
            'content_changed' => true,
            'flights_in_payload' => 1,
            'flights_changed' => 1,
            'events_written' => 1,
        ]);
        $body = gzencode('{"flights":[]}');
        $raw = $db->table('raw_responses')->insertGetId([
            'received_at' => DB::raw("UTC_TIMESTAMP(6) - INTERVAL {$rawAgeDays} DAY"),
            'sha256' => hash('sha256', '{"flights":[]}'),
            'body' => $body,
            'bytes_raw' => 14,
            'bytes_compressed' => strlen($body),
            'http_status' => 200,
            'endpoint' => '/api/flights',
        ]);
        $flight = $db->table('flights')->insertGetId([
            'aodb_id' => 'TEST-'.bin2hex(random_bytes(4)),
            'record' => '{}',
            'flow' => 'DEP',
            'full_flight_number' => 'WB452',
            'sobt' => '2026-10-29 07:40:00',
            'reference_time' => '2026-10-29 07:40:00',
            'reference_time_source' => 'sobt',
            'first_seen_at' => '2026-10-29 05:00:01.000000',
            'last_seen_at' => '2026-10-29 05:00:01.000000',
        ]);
        $version = $db->table('flight_versions')->insertGetId([
            'flight_id' => $flight,
            'version_no' => 1,
            'valid_from' => '2026-10-29 05:00:01.000000',
            'poll_id' => $poll,
            'cause' => 'aodb',
            'record' => '{}',
            'flow' => 'DEP',
            'full_flight_number' => 'WB452',
            'sobt' => '2026-10-29 07:40:00',
            'reference_time' => '2026-10-29 07:40:00',
            'reference_time_source' => 'sobt',
            'in_feed' => true,
        ]);
        $event = $db->table('change_events')->insertGetId([
            'flight_id' => $flight,
            'version_id' => $version,
            'kind' => 'first_seen',
            'new_value' => json_encode(['full_flight_number' => 'WB452', 'sobt' => '2026-10-29T07:40:00Z']),
            'source' => 'aodb',
            'poll_id' => $poll,
            'observed_at' => '2026-10-29 05:00:01.000000',
        ]);

        return [
            'polls' => $poll,
            'raw_responses' => $raw,
            'flights' => $flight,
            'flight_versions' => $version,
            'change_events' => $event,
        ];
    }

    private function assertRefused(callable $query, int $errno, string $message): void
    {
        try {
            $query();
            $this->fail("Expected the statement to be refused ({$message}).");
        } catch (QueryException $e) {
            $this->assertSame($errno, $e->errorInfo[1]);
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    private function runUpdate(Connection $db, string $table, int $id): int
    {
        // A harmless self-assignment: the statement, not the value, is refused.
        return $db->update("UPDATE {$table} SET id = id WHERE id = ?", [$id]);
    }

    private function runDelete(Connection $db, string $table, int $id): int
    {
        return $db->delete("DELETE FROM {$table} WHERE id = ?", [$id]);
    }

    public static function runtimeRefusals(): array
    {
        return [
            'UPDATE polls' => ['UPDATE', 'polls'],
            'DELETE polls' => ['DELETE', 'polls'],
            'UPDATE flight_versions' => ['UPDATE', 'flight_versions'],
            'DELETE flight_versions' => ['DELETE', 'flight_versions'],
            'UPDATE change_events' => ['UPDATE', 'change_events'],
            'DELETE change_events' => ['DELETE', 'change_events'],
            'UPDATE raw_responses' => ['UPDATE', 'raw_responses'],
            'DELETE flights' => ['DELETE', 'flights'],
        ];
    }

    #[DataProvider('runtimeRefusals')]
    public function test_runtime_connection_is_refused_by_grants(string $verb, string $table): void
    {
        $db = DB::connection('mariadb');
        $ids = $this->seedHistory($db);

        $this->assertRefused(
            fn () => $verb === 'UPDATE' ? $this->runUpdate($db, $table, $ids[$table]) : $this->runDelete($db, $table, $ids[$table]),
            1142,
            "{$verb} command denied",
        );
    }

    public static function triggerRefusals(): array
    {
        return [
            'UPDATE polls' => ['UPDATE', 'polls'],
            'DELETE polls' => ['DELETE', 'polls'],
            'UPDATE flight_versions' => ['UPDATE', 'flight_versions'],
            'DELETE flight_versions' => ['DELETE', 'flight_versions'],
            'UPDATE change_events' => ['UPDATE', 'change_events'],
            'DELETE change_events' => ['DELETE', 'change_events'],
            'UPDATE raw_responses' => ['UPDATE', 'raw_responses'],
        ];
    }

    #[DataProvider('triggerRefusals')]
    public function test_triggers_refuse_even_the_ddl_connection(string $verb, string $table): void
    {
        $db = DB::connection('mariadb_ddl');
        $ids = $this->seedHistory($db);

        try {
            $verb === 'UPDATE' ? $this->runUpdate($db, $table, $ids[$table]) : $this->runDelete($db, $table, $ids[$table]);
            $this->fail("{$verb} on {$table} was allowed.");
        } catch (QueryException $e) {
            $this->assertSame('45000', $e->errorInfo[0]);
            $this->assertStringContainsString($table, $e->errorInfo[2]);
        }

        $this->assertSame(1, $db->table($table)->where('id', $ids[$table])->count());
    }

    public function test_ddl_delete_of_raw_response_89_days_old_is_refused(): void
    {
        $db = DB::connection('mariadb_ddl');
        $ids = $this->seedHistory($db, rawAgeDays: 89);

        $this->assertRefused(fn () => $this->runDelete($db, 'raw_responses', $ids['raw_responses']), 1644, 'younger than 90 days');
        $this->assertSame(1, $db->table('raw_responses')->where('id', $ids['raw_responses'])->count());
    }

    public function test_ddl_delete_of_raw_response_91_days_old_is_allowed(): void
    {
        $db = DB::connection('mariadb_ddl');
        $ids = $this->seedHistory($db, rawAgeDays: 91);

        $this->assertSame(1, $this->runDelete($db, 'raw_responses', $ids['raw_responses']));
    }

    public function test_runtime_delete_of_recent_raw_response_is_refused_by_trigger(): void
    {
        $db = DB::connection('mariadb');
        $ids = $this->seedHistory($db);

        try {
            $this->runDelete($db, 'raw_responses', $ids['raw_responses']);
            $this->fail('DELETE of a recent raw response was allowed.');
        } catch (QueryException $e) {
            $this->assertSame('45000', $e->errorInfo[0]);
            $this->assertStringContainsString('raw_responses', $e->errorInfo[2]);
        }
    }

    public function test_runtime_delete_of_raw_response_91_days_old_is_allowed(): void
    {
        $db = DB::connection('mariadb');
        $ids = $this->seedHistory($db, rawAgeDays: 91);

        $this->assertSame(1, $this->runDelete($db, 'raw_responses', $ids['raw_responses']));
    }

    public function test_runtime_connection_can_insert_history_and_update_flights(): void
    {
        $db = DB::connection('mariadb');
        $ids = $this->seedHistory($db);

        foreach (['polls', 'raw_responses', 'flight_versions', 'change_events'] as $table) {
            $this->assertSame(1, $db->table($table)->where('id', $ids[$table])->count(), $table);
        }

        $this->assertSame(1, $db->table('flights')->where('id', $ids['flights'])->update(['reg' => '9XR-WP']));
        $this->assertSame('9XR-WP', $db->table('flights')->where('id', $ids['flights'])->value('reg'));
    }
}
