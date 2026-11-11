<?php

namespace Tests\Feature\Api;

use App\Quality\FlagResolver;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Collector\PollTestCase;

/**
 * G3, G4, G6, G7, G8: representation, duplicates, as_of, windows, freshness.
 */
class ApiFlightsTest extends PollTestCase
{
    use ApiHelpers;

    private const TK606_ARR_IST = 'bb7871bd-6796-4a27-a1fa-52dccc94b3a2';

    private const WB434_DEP = 'd7e33768-bfbc-4403-9814-dc153b601843';

    public function test_kq470_is_served_as_nbo_and_the_quality_group_shows_the_correction(): void
    {
        $plain = $this->consumerKey(['route']);
        $quality = $this->consumerKey(['route', 'quality']);
        $this->poll();

        $flight = $this->flightIn($this->api('flights?'.self::POLL_WINDOW, $plain), self::KQ470);
        $this->assertSame(['NBO', 'HKJK'], [$flight['adepIata'], $flight['adepIcao']]);
        $this->assertArrayNotHasKey('corrections', $flight['hub']);

        $hub = $this->flightIn($this->api('flights?'.self::POLL_WINDOW, $quality), self::KQ470)['hub'];
        $this->assertSame([
            ['field' => 'adepIata', 'aodb' => 'NB0', 'served' => 'NBO', 'reference' => 'airport_code_corrections:NB0'],
            ['field' => 'adepIcao', 'aodb' => null, 'served' => 'HKJK', 'reference' => 'airport_code_corrections:NB0'],
        ], $hub['corrections']);
        $rules = array_column($hub['quality'], 'rule');
        $this->assertContains('format_invalid', $rules);
        $this->assertContains('no_actual_after_schedule', $rules);
        $this->assertSame('2026-10-29T07:42:30Z', $hub['quality'][0]['since']);
    }

    public function test_rotation_and_min_turnaround_appear_with_their_groups(): void
    {
        $key = $this->consumerKey(['rotation', 'aircraft']);
        $payload = $this->sample();
        self::record($payload, self::WB434_DEP)->mtt = 45;
        $this->poll($payload);

        $response = $this->api('flights?'.self::POLL_WINDOW, $key);
        $arr = $this->flightIn($response, self::WB464_ARR)['hub'];
        $this->assertSame([self::WB434_DEP, null, 'registration'], [$arr['rotationNext'], $arr['rotationPrev'], $arr['rotationSource']]);
        $this->assertNull($arr['minTurnaroundMinutes'], 'arrivals have none');

        $dep = $this->flightIn($response, self::WB434_DEP);
        $this->assertSame([null, self::WB464_ARR, 45], [$dep['hub']['rotationNext'], $dep['hub']['rotationPrev'], $dep['hub']['minTurnaroundMinutes']]);
        $this->assertSame(45, $dep['mtt']);
        $this->assertArrayNotHasKey('rotationNext', $this->flightIn($this->api('flights?'.self::POLL_WINDOW, $this->consumerKey(['route'])), self::WB464_ARR)['hub']);
    }

    public function test_every_allowed_top_level_field_is_present_and_no_internal_ids(): void
    {
        $key = $this->consumerKey(array_keys(config('api_fields.groups')));
        $this->poll();

        $flight = $this->flightIn($this->api('flights?'.self::POLL_WINDOW, $key), self::WB464A);
        $this->assertArrayHasKey('sobt', $flight);
        $this->assertNull($flight['sobt']);
        $this->assertSame('2026-10-29T00:45:00Z', $flight['etot']);
        $this->assertSame('2026-10-29T00:45:00Z', $flight['hub']['referenceTime']);
        $internal = DB::table('flights')->where('aodb_id', self::WB464A)->value('id');
        $this->assertArrayNotHasKey('flight_id', $flight);
        $this->assertNotSame((string) $internal, (string) $flight['id']);
    }

    public function test_a_same_as_flight_is_hidden_from_lists_but_reachable_by_id_and_in_changes(): void
    {
        $key = $this->consumerKey(['route']);
        $this->poll();
        $flag = DB::table('quality_flags')->where(['flight_id' => $this->flight(self::TK606_ARR_TK)->id, 'rule' => 'duplicate_suspected'])->sole();
        (new FlagResolver)->resolve($flag->id, 'same_as', 'Dany Kabalisa');

        $list = $this->api('flights?'.self::POLL_WINDOW, $key)->json('data');
        $tk606Arr = array_values(array_filter($list, fn ($f) => $f['fullFlightNumber'] === 'TK606' && $f['flow'] === 'ARR'));
        $this->assertSame([self::TK606_ARR_IST], array_column($tk606Arr, 'id'));

        $hidden = $this->api('flights/'.self::TK606_ARR_TK, $key)->assertOk()->json('data');
        $this->assertSame(self::TK606_ARR_IST, $hidden['hub']['sameAs']);

        $sameAs = array_values(array_filter($this->api('changes?cursor=0&limit=1000', $key)->json('data'), fn ($e) => $e['field'] === 'hub.sameAs'));
        $this->assertSame([[self::TK606_ARR_TK, null, self::TK606_ARR_IST, 'manual']], array_map(fn ($e) => [$e['aodbId'], $e['old'], $e['new'], $e['source']], $sameAs));
    }

    public function test_removed_flights_only_with_include_removed(): void
    {
        $key = $this->consumerKey(['route']);
        $this->poll();
        $this->aodb->scriptById(fn (array $ids) => []);
        $this->poll(self::without($this->sample(), self::WB711));

        $this->assertNotContains(self::WB711, array_column($this->api('flights?'.self::POLL_WINDOW, $key)->json('data'), 'id'));
        $removed = $this->flightIn($this->api('flights?'.self::POLL_WINDOW.'&include_removed=1', $key), self::WB711);
        $this->assertFalse($removed['hub']['inFeed']);
    }

    public function test_as_of_lists_a_flight_that_moved_out_of_the_window_as_of_before_the_move(): void
    {
        $key = $this->consumerKey(['route', 'schedule']);
        $this->poll();
        $this->travel(5)->minutes();
        $payload = $this->sample();
        self::record($payload, self::WB711)->sibt = '2026-10-30T08:00:00Z';
        $this->poll($payload);

        $now = $this->api('flights?day=2026-10-29', $key);
        $this->assertNotContains(self::WB711, array_column($now->json('data'), 'id'));

        $before = $this->api('flights?day=2026-10-29&as_of=2026-10-29T07:45:00Z', $key)->assertOk();
        $flight = $this->flightIn($before, self::WB711);
        $this->assertSame('2026-10-29T05:30:00Z', $flight['sibt']);
        $this->assertSame('2026-10-29T07:45:00Z', $before->json('meta.requestedAsOf'));
    }

    public function test_windows_day_is_the_kigali_day_and_invalid_windows_are_422(): void
    {
        $key = $this->consumerKey(['route']);
        $this->poll();

        $day = $this->api('flights?day=2026-10-29', $key)->assertOk();
        $this->assertSame(['from' => '2026-10-28T22:00:00Z', 'to' => '2026-10-29T22:00:00Z'], $day->json('meta.window'));
        $times = array_column(array_column($day->json('data'), 'hub'), 'referenceTime');
        $sorted = $times;
        sort($sorted);
        $this->assertSame($sorted, $times, 'sorted by reference time');

        foreach ([
            'flights?from=2026-10-20T00:00:00Z&to=2026-10-28T00:00:00Z',
            'flights?from=2026-10-29T05:00:00Z&to=2026-10-29T05:00:00Z',
            'flights?from=2026-10-29T06:00:00Z&to=2026-10-29T05:00:00Z',
            'flights',
            'flights?day=2026-10-29&from=2026-10-29T05:00:00Z&to=2026-10-29T06:00:00Z',
            'flights?from=2026-10-29T05:00:00&to=2026-10-29T06:00:00Z',
            'flights?day=2026-10-29&flow=BOTH',
            'flights?day=2026-10-29&as_of=yesterday',
        ] as $uri) {
            $this->api($uri, $key)->assertStatus(422)->assertJsonPath('error', 'INVALID_PARAMETER');
        }
    }

    public function test_first_seen_is_the_flight_with_served_values_and_hub_times_have_no_fraction(): void
    {
        $key = $this->consumerKey(['route', 'quality']);
        $this->poll();

        $history = $this->api('flights/'.self::KQ470.'?history=1', $key)->json('history');
        $first = $history[0];
        $this->assertSame('first_seen', $first['kind']);
        $this->assertSame(['NBO', 'HKJK'], [$first['new']['adepIata'], $first['new']['adepIcao']]);
        $this->assertSame([], $first['new']['hub']['quality']);
        $this->assertSame('2026-10-29T06:00:00Z', $first['new']['hub']['referenceTime']);
        $this->assertSame('2026-10-29T07:42:30Z', $first['observedAt']);

        $response = $this->api('flights?day=2026-10-29&as_of=2026-10-29T07:42:30Z', $key);
        foreach ([$response->json('meta.asOf'), $response->json('meta.window.from'), $response->json('meta.requestedAsOf'),
            $this->flightIn($response, self::KQ470)['hub']['quality'][0]['since']] as $time) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $time);
        }
    }

    public function test_feed_becomes_stale_15_minutes_after_the_last_ok_poll(): void
    {
        $key = $this->consumerKey(['route']);
        $this->poll();

        $this->assertSame(['2026-10-29T07:42:30Z', 'ok'], [$this->api('health', $key)->json('meta.asOf'), $this->api('health', $key)->json('meta.feed')]);

        $this->travel(15)->minutes();
        $this->assertSame('ok', $this->api('flights?day=2026-10-29', $key)->json('meta.feed'));

        $this->travel(1)->seconds();
        $this->assertSame('stale', $this->api('flights?day=2026-10-29', $key)->json('meta.feed'));
        $this->assertSame('stale', $this->api('health', $key)->json('data.feed'));
    }
}
