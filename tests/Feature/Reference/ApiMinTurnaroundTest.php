<?php

namespace Tests\Feature\Reference;

use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\ApiHelpers;

/**
 * C3: hub.minTurnaroundMinutes falls back to the aircraft type's minimum turnaround.
 */
class ApiMinTurnaroundTest extends ReferenceDataTestCase
{
    use ApiHelpers;

    private const WB452_DEP = 'f63c1c17-2c46-4031-8bdf-df1f72071004';

    public function test_min_turnaround_comes_from_the_aircraft_type_when_the_aodb_has_no_mtt(): void
    {
        $id = $this->aircraftType('WB', 'DH8', 'DH8D', 'Dash 8-400 (TEST)', 'M');
        DB::connection('mariadb_ddl')->table('aircraft_types')->where('id', $id)->update(['min_turnaround_minutes' => 35]);
        $key = $this->consumerKey(['aircraft']);
        $this->poll();

        $response = $this->api('flights?'.self::POLL_WINDOW, $key)->assertOk();

        $this->assertSame(35, $this->flightIn($response, self::WB452_DEP)['hub']['minTurnaroundMinutes']);
        $this->assertNull($this->flightIn($response, self::WB464A)['hub']['minTurnaroundMinutes'], 'no mtt, no type row');
    }
}
