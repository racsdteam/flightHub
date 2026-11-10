<?php

namespace Tests\Feature\Collector;

use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Second polls: variants of the sample built inside the tests.
 */
class PollChangesTest extends PollTestCase
{
    private function counts(): array
    {
        return [
            DB::table('raw_responses')->count(),
            DB::table('flight_versions')->count(),
            DB::table('change_events')->count(),
        ];
    }

    public function test_identical_payload_writes_nothing_but_last_seen(): void
    {
        $this->poll();
        $before = $this->counts();
        $this->travel(1)->minutes();

        $poll = $this->poll();

        $this->assertSame($before, $this->counts());
        $this->assertSame('ok', $poll->outcome);
        $this->assertSame(0, (int) $poll->content_changed);
        $this->assertSame(0, (int) $poll->events_written);
        $this->assertSame(DB::table('raw_responses')->value('id'), $poll->raw_response_id);
        $this->assertSame('2026-10-29 07:43:30.000000', $this->flight(self::WB711)->last_seen_at);
    }

    public function test_same_content_in_another_key_order_is_unchanged(): void
    {
        $this->poll();
        $before = $this->counts();

        $reordered = new stdClass;
        $reordered->flights = array_map(fn (stdClass $r) => self::reverseKeys($r), $this->sample()->flights);
        $poll = $this->poll($reordered);

        $this->assertSame($before, $this->counts());
        $this->assertSame(0, (int) $poll->content_changed);
    }

    public function test_wb711_lands_one_version_three_events_reference_time_unchanged(): void
    {
        $this->poll();
        $payload = $this->sample();
        $wb711 = self::record($payload, self::WB711);
        $wb711->aldt = '2026-10-29T05:18:00Z';
        $wb711->aibt = '2026-10-29T05:25:00Z';
        $wb711->status = 'TERMINATED';

        $poll = $this->poll($payload);

        $this->assertSame(1, (int) $poll->flights_changed);
        $this->assertSame(3, (int) $poll->events_written);
        $this->assertCount(2, $this->versions(self::WB711));
        $events = collect($this->events(self::WB711, 'field_changed'));
        $this->assertSame(['aibt', 'aldt', 'status'], $events->pluck('field')->all());
        $this->assertSame([null, '"2026-10-29T05:25:00Z"'], [$events[0]->old_value, $events[0]->new_value]);
        $this->assertSame(['"IN_FLIGHT"', '"TERMINATED"'], [$events[2]->old_value, $events[2]->new_value]);
        $this->assertTrue($events->every(fn ($e) => $e->source === 'aodb'));

        $flight = $this->flight(self::WB711);
        $this->assertSame('2026-10-29 05:30:00', $flight->reference_time);
        $this->assertSame('2026-10-29 05:18:00', $flight->aldt);
        $this->assertSame('TERMINATED', $flight->status);
        $this->assertSame(2, (int) $flight->current_version_no);
        $this->assertSame((int) $this->versions(self::WB711)[1]->id, (int) $flight->current_version_id);
    }

    public function test_removed_pkg_is_an_event_to_null(): void
    {
        $this->poll();
        $payload = $this->sample();
        unset(self::record($payload, self::WB711)->pkg);

        $this->poll($payload);

        $events = $this->events(self::WB711, 'field_changed');
        $this->assertCount(1, $events);
        $this->assertSame(['pkg', '"A5"', null], [$events[0]->field, $events[0]->old_value, $events[0]->new_value]);
        $this->assertNull($this->flight(self::WB711)->pkg);
    }

    public function test_null_field_that_becomes_absent_is_no_event(): void
    {
        $this->poll();
        $payload = $this->sample();
        unset(self::record($payload, self::KQ478D)->dlCodes->ais);
        unset(self::record($payload, self::WB464_ARR)->aircraftDetails->mtow);
        $before = $this->counts();

        $poll = $this->poll($payload);

        $this->assertSame(1, (int) $poll->content_changed);
        $this->assertSame([$before[0] + 1, $before[1], $before[2]], $this->counts());
    }

    public function test_nested_pax_details_change_is_one_event_with_dotted_path(): void
    {
        $first = $this->sample();
        self::record($first, self::WB711)->paxDetails = (object) ['pmr' => 1, 'um' => 0];
        $this->poll($first);

        $second = $this->sample();
        self::record($second, self::WB711)->paxDetails = (object) ['pmr' => 2, 'um' => 0];
        $this->poll($second);

        $events = $this->events(self::WB711, 'field_changed');
        $this->assertCount(1, $events);
        $this->assertSame(['paxDetails.pmr', '1', '2'], [$events[0]->field, $events[0]->old_value, $events[0]->new_value]);
    }

    public function test_dl_codes_typeb_change_is_one_event_with_the_whole_array(): void
    {
        $this->poll();
        $payload = $this->sample();
        $dlCodes = self::record($payload, self::KQ478D)->dlCodes;
        $dlCodes->typeb[0]->delay = 5;
        $dlCodes->typeb[] = (object) ['code' => '81', 'delay' => 3, 'info' => null];

        $this->poll($payload);

        $events = $this->events(self::KQ478D, 'field_changed');
        $this->assertCount(1, $events);
        $this->assertSame('dlCodes.typeb', $events[0]->field);
        $this->assertSame([['code' => '93', 'delay' => 1, 'info' => null]], json_decode($events[0]->old_value, true));
        $this->assertCount(2, json_decode($events[0]->new_value, true));
        $this->assertSame(5, json_decode($events[0]->new_value, true)[0]['delay']);
    }

    public function test_value_that_does_not_fit_leaves_the_column_null(): void
    {
        $payload = $this->sample();
        self::record($payload, self::WB711)->paxCapa = 'abc';

        $poll = $this->poll($payload);

        $flight = $this->flight(self::WB711);
        $this->assertSame('ok', $poll->outcome);
        $this->assertNull($flight->pax_capa);
        $this->assertNull($flight->pax_capa_aodb);
        $this->assertSame('abc', json_decode($flight->record)->paxCapa);
        $this->assertSame('abc', json_decode($this->versions(self::WB711)[0]->record)->paxCapa);
    }

    public function test_airport_code_that_becomes_nb0_gets_aodb_and_correction_events(): void
    {
        $this->poll();
        $payload = $this->sample();
        self::record($payload, self::WB711)->adepIata = 'NB0';

        $this->poll($payload);

        $events = collect($this->events(self::WB711, 'field_changed'));
        $this->assertSame([['adepIata', 'aodb', '"LHR"', '"NB0"'], ['adepIata', 'correction', '"LHR"', '"NBO"']],
            $events->map(fn ($e) => [$e->field, $e->source, $e->old_value, $e->new_value])->all());
        // The record still has adepIcao (EGLL): the correction does not fill it.
        $this->assertSame('EGLL', $this->flight(self::WB711)->adep_icao);
        $this->assertSame('NBO', $this->flight(self::WB711)->adep_iata);
    }

    private static function reverseKeys(stdClass $object): stdClass
    {
        $reversed = new stdClass;
        foreach (array_reverse(get_object_vars($object), true) as $key => $value) {
            $reversed->{$key} = $value instanceof stdClass ? self::reverseKeys($value) : $value;
        }

        return $reversed;
    }
}
