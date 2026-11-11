<?php

namespace Tests\Feature\Quality;

use Illuminate\Support\Facades\DB;
use Tests\Feature\Collector\PollTestCase;

/**
 * E1: the sample polled and evaluated with now = 2026-10-29T07:42:30Z.
 * The expectations are the batch's; they are not adjusted to the result.
 */
class QualitySampleTest extends PollTestCase
{
    private const EXPECTED = [
        'duplicate_suspected|' => ['SN465 ARR', 'SN465 ARR', 'TK606 ARR', 'TK606 ARR', 'TK606 DEP', 'TK606 DEP'],
        'format_invalid|adepIata' => ['KQ470 ARR', 'KQ478 ARR', 'TK606 ARR'],
        'required_missing|adepIcao' => ['KQ470 ARR', 'KQ478 ARR', 'TK606 ARR'],
        'suffixed_flight_number|' => ['KQ478D ARR', 'WB464A DEP'],
        'actual_early|' => ['WB202 DEP', 'WB452 DEP', 'WB482 DEP'],
        'no_actual_after_schedule|' => ['KQ470 ARR', 'KQ471 DEP', 'MS835 ARR', 'MS835 DEP', 'WB103 ARR', 'WB601 DEP', 'WB602 ARR', 'WB701 ARR'],
        'in_flight_stale|' => ['WB711 ARR'],
        'type_missing|' => ['KQ478D ARR', 'KQ479 DEP', 'WB402 DEP', 'WB403 ARR', 'WB452 DEP', 'WB453 ARR', 'WB464A DEP', 'WB482 DEP', 'WB483 ARR', 'WB601 DEP', 'WB602 ARR'],
        'capacity_missing|' => ['KQ478D ARR', 'KQ479 DEP', 'MS835 ARR', 'MS835 DEP', 'TK606 ARR', 'TK606 ARR', 'TK606 DEP', 'TK606 DEP', 'WB464A DEP'],
    ];

    /**
     * @return array<string, list<string>> "rule|field" → sorted "FLIGHT FLOW"
     */
    private function openFlags(): array
    {
        $rows = DB::table('quality_flags')
            ->join('flights', 'flights.id', '=', 'quality_flags.flight_id')
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

        return $flags;
    }

    public function test_the_sample_produces_exactly_the_expected_flags(): void
    {
        $this->poll();

        $expected = self::EXPECTED;
        ksort($expected);
        $this->assertSame($expected, $this->openFlags());
        $this->assertSame(0, DB::table('quality_flags')->where('rule', 'value_unfit')->count());
    }

    public function test_every_flag_was_detected_once_by_the_hub(): void
    {
        $this->poll();

        $flags = DB::table('quality_flags')->count();
        $this->assertSame(46, $flags);
        $this->assertSame($flags, DB::table('quality_flag_events')->where(['kind' => 'detected', 'to_value' => 'open'])->whereNull('actor')->count());
        $this->assertSame($flags, DB::table('quality_flag_events')->count());
    }

    public function test_duplicates_name_each_other_and_kq479_is_not_one(): void
    {
        $this->poll();

        $tk606Arr = DB::table('flights')->where(['full_flight_number' => 'TK606', 'flow' => 'ARR'])->orderBy('id')->get();
        $a = DB::table('quality_flags')->where(['flight_id' => $tk606Arr[0]->id, 'rule' => 'duplicate_suspected'])->sole();
        $this->assertSame((int) $tk606Arr[1]->id, (int) $a->related_flight_id);
        $this->assertSame('duplicate_suspected||'.$tk606Arr[1]->id, $a->flag_key);
        $this->assertSame($tk606Arr[1]->aodb_id, json_decode($a->details)->related_aodb_id);

        $kq479 = DB::table('flights')->where(['full_flight_number' => 'KQ479', 'flow' => 'DEP'])->pluck('id');
        $this->assertSame(0, DB::table('quality_flags')->whereIn('flight_id', $kq479)->where('rule', 'duplicate_suspected')->count());
    }

    public function test_format_is_checked_on_the_value_as_received_even_when_corrected(): void
    {
        $this->poll();

        $flag = DB::table('quality_flags')->where(['flight_id' => $this->flight(self::KQ470)->id, 'rule' => 'format_invalid'])->sole();
        $this->assertSame(['value' => 'NB0', 'pattern' => '^[A-Z]{3}$'], json_decode($flag->details, true));
        $this->assertSame('NBO', $this->flight(self::KQ470)->adep_iata);
    }
}
