<?php

namespace Tests\Feature\Collector;

use App\Aodb\CanonicalJson;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

class PollSampleTest extends PollTestCase
{
    public function test_first_poll_of_the_sample(): void
    {
        $poll = $this->poll();

        $this->assertSame('ok', $poll->outcome);
        $this->assertSame(51, (int) $poll->flights_in_payload);
        $this->assertSame(51, (int) $poll->flights_new);
        $this->assertSame(0, (int) $poll->flights_changed);
        $this->assertSame(1, (int) $poll->content_changed);
        $this->assertNull($poll->warnings);
        $this->assertSame('2026-10-27 22:00:00', $poll->request_window_from);
        $this->assertSame('2026-10-30 22:00:00', $poll->request_window_to);

        $this->assertSame(51, DB::table('flights')->count());
        $this->assertSame(51, DB::table('flight_versions')->count());
        $this->assertSame(51, DB::table('change_events')->where('kind', 'first_seen')->count());
        // Plus 4 correction events: adepIata and adepIcao of KQ470 and KQ478.
        $this->assertSame(55, DB::table('change_events')->count());
        $this->assertSame(55, (int) $poll->events_written);

        $raw = DB::table('raw_responses')->sole();
        $this->assertSame((int) $raw->id, (int) $poll->raw_response_id);
        $body = (string) file_get_contents(base_path('tests/Fixtures/aodb/'.self::SAMPLE));
        $this->assertSame($body, gzdecode($raw->body));
        $this->assertSame(CanonicalJson::sha256(CanonicalJson::decode($body)), $raw->sha256);
        $this->assertSame('/flights/v1/airport', $raw->endpoint);
    }

    public function test_two_tk606_arr_and_two_kq479_dep_are_two_flights_each(): void
    {
        $this->poll();

        $this->assertSame(2, DB::table('flights')->where(['full_flight_number' => 'TK606', 'flow' => 'ARR'])->count());
        $this->assertSame(2, DB::table('flights')->where(['full_flight_number' => 'KQ479', 'flow' => 'DEP'])->count());
    }

    public function test_nb0_is_served_as_nbo_hkjk_with_correction_events(): void
    {
        $this->poll();
        $correction = DB::table('airport_code_corrections')->where('aodb_value', 'NB0')->sole();

        foreach ([self::KQ470, self::KQ478] as $id) {
            $flight = $this->flight($id);
            $this->assertSame('NB0', $flight->adep_iata_aodb);
            $this->assertSame('NBO', $flight->adep_iata);
            $this->assertNull($flight->adep_icao_aodb);
            $this->assertSame('HKJK', $flight->adep_icao);

            $events = $this->events($id);
            $this->assertCount(3, $events);
            $this->assertSame('first_seen', $events[0]->kind);
            $byField = collect($events)->where('source', 'correction')->keyBy('field');
            $this->assertSame(['"NB0"', '"NBO"'], [$byField['adepIata']->old_value, $byField['adepIata']->new_value]);
            $this->assertSame([null, '"HKJK"'], [$byField['adepIcao']->old_value, $byField['adepIcao']->new_value]);
            $this->assertSame("airport_code_corrections:{$correction->id}", $byField['adepIata']->source_ref);
            $this->assertSame('field_changed', $byField['adepIata']->kind);
        }
    }

    public function test_reference_times_of_the_carry_overs(): void
    {
        $this->poll();

        $this->assertSame(['2026-10-29 00:45:00', 'etot'], $this->ref(self::WB464A));
        $this->assertSame(['2026-10-29 05:09:00', 'eibt'], $this->ref(self::KQ478D));
        $this->assertSame(['2026-10-29 05:52:00', 'etot'], $this->ref(self::KQ479_MORNING));
        $this->assertSame(['2026-10-29 05:30:00', 'sibt'], $this->ref(self::WB711));
    }

    public function test_tk606_arr_with_adep_iata_tk_stays_as_received(): void
    {
        $this->poll();
        $flight = $this->flight(self::TK606_ARR_TK);

        $this->assertSame('TK', $flight->adep_iata_aodb);
        $this->assertSame('TK', $flight->adep_iata);
        $this->assertNull($flight->adep_icao);
        $this->assertCount(1, $this->events(self::TK606_ARR_TK));
    }

    public function test_record_is_kept_whole_with_sorted_keys_and_typed_columns_parsed(): void
    {
        $this->poll();
        $flight = $this->flight(self::WB464_ARR);
        $record = self::record($this->sample(), self::WB464_ARR);

        $this->assertSame(CanonicalJson::encode($record), $flight->record);
        $this->assertSame(CanonicalJson::encode($record), $this->versions(self::WB464_ARR)[0]->record);
        $this->assertSame('B737-800', $flight->ac_type_name);
        $this->assertSame('M', $flight->wtc);
        $this->assertNull($flight->mtow);
        $this->assertSame(154, (int) $flight->pax_capa);
        $this->assertSame('2026-10-29 04:13:00', $flight->aldt);
    }

    public function test_first_seen_holds_record_and_served_values(): void
    {
        $this->poll();
        $event = $this->events(self::KQ470, 'first_seen')[0];
        $value = json_decode($event->new_value);

        $this->assertEquals(self::record($this->sample(), self::KQ470), $value->record);
        $this->assertSame('NBO', $value->served->adepIata);
        $this->assertSame('HKJK', $value->served->adepIcao);
        $this->assertSame('aodb', $event->source);
        $this->assertNull($event->old_value);
    }

    public function test_poll_runs_on_the_runtime_connection_and_grants_still_match(): void
    {
        $connections = [];
        DB::listen(function (QueryExecuted $query) use (&$connections) {
            $connections[$query->connectionName] = true;
        });

        $this->poll();

        $this->assertSame(['mariadb'], array_keys($connections));
        $this->assertSame(0, $this->artisan('hub:check-grants')->run());
    }

    private function ref(string $id): array
    {
        $flight = $this->flight($id);

        return [$flight->reference_time, $flight->reference_time_source];
    }
}
