<?php

namespace Tests\Feature\Reference;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * E6: the runtime user may only read the reference tables (editing comes with
 * the screens; rows are added by migrations after Dany's review).
 */
class ReferenceTablesProtectionTest extends TestCase
{
    public static function refusals(): array
    {
        return [
            'INSERT aircraft_types' => ['INSERT', "INSERT INTO aircraft_types (airline_iata, ac_type_iata, reviewed_by, reviewed_on) VALUES ('WB', 'DH8', 'x', '2026-11-11')"],
            'UPDATE aircraft_types' => ['UPDATE', "UPDATE aircraft_types SET wtc = 'M' WHERE id = 0"],
            'DELETE aircraft_types' => ['DELETE', 'DELETE FROM aircraft_types WHERE id = 0'],
            'INSERT stands' => ['INSERT', "INSERT INTO stands (code, reviewed_by, reviewed_on) VALUES ('A9', 'x', '2026-11-11')"],
            'UPDATE stands' => ['UPDATE', 'UPDATE stands SET active = 0 WHERE id = 0'],
            'DELETE stands' => ['DELETE', 'DELETE FROM stands WHERE id = 0'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_runtime_is_refused_writes_on_reference_tables(string $verb, string $sql): void
    {
        try {
            DB::connection('mariadb')->statement($sql);
            $this->fail("{$verb} was allowed");
        } catch (QueryException $e) {
            $this->assertSame(1142, $e->errorInfo[1]);
            $this->assertStringContainsString("{$verb} command denied", $e->getMessage());
        }
    }

    public function test_runtime_can_read_reference_tables(): void
    {
        $this->assertSame(0, DB::connection('mariadb')->table('aircraft_types')->count());
        $this->assertSame(0, DB::connection('mariadb')->table('stands')->count());
    }
}
