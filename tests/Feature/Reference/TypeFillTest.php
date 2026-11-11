<?php

namespace Tests\Feature\Reference;

use Illuminate\Support\Facades\DB;

/**
 * E2: type fill from aircraft_types (D-033) with a TEST-ONLY row.
 */
class TypeFillTest extends ReferenceDataTestCase
{
    private const DH8 = ['WB601 DEP', 'WB483 ARR', 'WB453 ARR', 'WB403 ARR', 'WB602 ARR', 'WB402 DEP', 'WB452 DEP', 'WB482 DEP'];

    private function dh8Flights()
    {
        return DB::table('flights')->where(['airline_iata' => 'WB', 'ac_type_iata' => 'DH8'])->get();
    }

    public function test_wb_dh8_flights_get_type_name_and_wtc_with_correction_events(): void
    {
        $typeId = $this->aircraftType('WB', 'DH8', 'DH8D', 'Dash 8-400 (TEST)', 'M');

        $this->poll();

        $flights = $this->dh8Flights();
        $this->assertEqualsCanonicalizing(self::DH8, $flights->map(fn ($f) => $f->full_flight_number.' '.$f->flow)->all());
        foreach ($flights as $flight) {
            $this->assertSame(['DH8D', 'Dash 8-400 (TEST)', 'M'], [$flight->ac_type_icao, $flight->ac_type_name, $flight->wtc]);
            $this->assertSame([null, null, null], [$flight->ac_type_icao_aodb, $flight->ac_type_name_aodb, $flight->wtc_aodb]);
            $this->assertSame(65, (int) $flight->pax_capa);

            $events = DB::table('change_events')->where(['flight_id' => $flight->id, 'source' => 'correction'])->orderBy('field')->get();
            $this->assertSame(['acTypeIcao', 'aircraftDetails.acTypeName', 'aircraftDetails.wtc'], $events->pluck('field')->all());
            $this->assertTrue($events->every(fn ($e) => $e->source_ref === "aircraft_types:{$typeId}" && $e->old_value === null));
            $this->assertSame('"DH8D"', $events[0]->new_value);
        }
    }

    public function test_type_missing_drops_from_11_to_3_and_capacity_is_untouched(): void
    {
        $this->aircraftType('WB', 'DH8', 'DH8D', 'Dash 8-400 (TEST)', 'M');

        $this->poll();

        $typeMissing = DB::table('quality_flags')->join('flights', 'flights.id', '=', 'quality_flags.flight_id')
            ->where(['rule' => 'type_missing', 'state' => 'open'])
            ->pluck('flights.aodb_id')->all();
        $this->assertEqualsCanonicalizing([self::WB464A, self::KQ478D, self::KQ479_MORNING], $typeMissing);
        $this->assertSame(9, DB::table('quality_flags')->where(['rule' => 'capacity_missing', 'state' => 'open'])->count());
    }

    public function test_flags_after_4b_with_the_test_row(): void
    {
        $this->aircraftType('WB', 'DH8', 'DH8D', 'Dash 8-400 (TEST)', 'M');

        $this->poll();

        $rows = DB::table('quality_flags')->join('flights', 'flights.id', '=', 'quality_flags.flight_id')
            ->where('quality_flags.state', 'open')
            ->get(['quality_flags.rule', 'quality_flags.field', 'flights.full_flight_number', 'flights.flow']);
        $flags = [];
        foreach ($rows as $row) {
            $flags[$row->rule.'|'.$row->field][] = $row->full_flight_number.' '.$row->flow;
        }
        foreach ($flags as &$list) {
            sort($list);
        }
        ksort($flags);

        $this->assertSame([
            'actual_early|' => ['WB202 DEP', 'WB452 DEP', 'WB482 DEP'],
            'capacity_missing|' => ['KQ478D ARR', 'KQ479 DEP', 'MS835 ARR', 'MS835 DEP', 'TK606 ARR', 'TK606 ARR', 'TK606 DEP', 'TK606 DEP', 'WB464A DEP'],
            'duplicate_suspected|' => ['SN465 ARR', 'SN465 ARR', 'TK606 ARR', 'TK606 ARR', 'TK606 DEP', 'TK606 DEP'],
            'format_invalid|adepIata' => ['KQ470 ARR', 'KQ478 ARR', 'TK606 ARR'],
            'in_flight_stale|' => ['WB711 ARR'],
            'no_actual_after_schedule|' => ['KQ470 ARR', 'KQ471 DEP', 'MS835 ARR', 'MS835 DEP', 'WB103 ARR', 'WB601 DEP', 'WB602 ARR', 'WB701 ARR'],
            'required_missing|adepIcao' => ['KQ470 ARR', 'KQ478 ARR', 'TK606 ARR'],
            'suffixed_flight_number|' => ['KQ478D ARR', 'WB464A DEP'],
            'type_missing|' => ['KQ478D ARR', 'KQ479 DEP', 'WB464A DEP'],
        ], $flags);
    }

    public function test_the_airlines_own_row_wins_over_any_airline(): void
    {
        $this->aircraftType('*', 'DH8', 'DH8C', 'Dash 8-300 (TEST)', 'M');
        $this->aircraftType('WB', 'DH8', 'DH8D', 'Dash 8-400 (TEST)', 'M');

        $this->poll();

        $this->assertSame(['DH8D'], $this->dh8Flights()->pluck('ac_type_icao')->unique()->values()->all());
    }

    public function test_any_airline_row_fills_when_the_airline_has_none(): void
    {
        $this->aircraftType('*', 'DH8', 'DH8D', null, null);

        $this->poll();

        $flight = $this->dh8Flights()->first();
        $this->assertSame(['DH8D', null, null], [$flight->ac_type_icao, $flight->ac_type_name, $flight->wtc]);
        $this->assertSame(1, DB::table('change_events')->where(['flight_id' => $flight->id, 'source' => 'correction'])->count());
    }

    public function test_an_aodb_value_is_never_overwritten(): void
    {
        $this->aircraftType('*', '738', 'XXXX', 'Not used (TEST)', 'L');

        $this->poll();

        $flight = $this->flight(self::WB464_ARR);
        $this->assertSame(['B738', 'B737-800', 'M'], [$flight->ac_type_icao, $flight->ac_type_name, $flight->wtc]);
    }
}
