<?php

namespace Tests\Feature\Reference;

use Illuminate\Support\Facades\DB;
use Tests\Feature\Collector\PollTestCase;

/**
 * Tests that need TEST-ONLY reference rows (aircraft_types, stands, extra
 * airport_code_corrections). The runtime user may only SELECT those tables,
 * so the rows are written and committed by the DDL connection, and the
 * runtime connection runs without the wrapping transaction (a REPEATABLE READ
 * snapshot would not see rows added mid-test). tearDown leaves
 * flighthub_test clean: history tables are truncated (TRUNCATE fires no
 * DELETE trigger; only the DDL user may run it) and test rows are removed.
 */
abstract class ReferenceDataTestCase extends PollTestCase
{
    protected $connectionsToTransact = [];

    private const TRUNCATED = [
        'change_events', 'flight_versions', 'quality_flag_events', 'quality_flags', 'flights',
        'raw_responses', 'polls', 'aircraft_types', 'stands',
    ];

    protected function tearDown(): void
    {
        $ddl = DB::connection('mariadb_ddl');
        $ddl->statement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::TRUNCATED as $table) {
            $ddl->statement("TRUNCATE TABLE {$table}");
        }
        $ddl->statement('SET FOREIGN_KEY_CHECKS = 1');
        // The NB0 row is the migration's seed (D-028); everything else is a test row.
        $ddl->table('airport_code_corrections')->where('aodb_value', '<>', 'NB0')->delete();

        parent::tearDown();
    }

    protected function aircraftType(string $airline, string $acTypeIata, ?string $icao, ?string $name, ?string $wtc): int
    {
        return DB::connection('mariadb_ddl')->table('aircraft_types')->insertGetId([
            'airline_iata' => $airline,
            'ac_type_iata' => $acTypeIata,
            'ac_type_icao' => $icao,
            'type_name' => $name,
            'wtc' => $wtc,
            'reviewed_by' => 'TEST ONLY',
            'reviewed_on' => '2026-11-11',
            'active' => true,
        ]);
    }

    protected function stand(string $code): void
    {
        DB::connection('mariadb_ddl')->table('stands')->insert([
            'code' => $code,
            'active' => true,
            'reviewed_by' => 'TEST ONLY',
            'reviewed_on' => '2026-11-11',
        ]);
    }
}
