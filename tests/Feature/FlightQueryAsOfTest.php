<?php

namespace Tests\Feature;

use App\Flights\FlightQuery;
use App\Flights\ReferenceTime;
use App\Flights\Window;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * as_of = what the hub knew at that moment (D-022). Versions are inserted
 * directly on the runtime connection; each test is rolled back.
 */
class FlightQueryAsOfTest extends TestCase
{
    private Window $day;

    protected function setUp(): void
    {
        parent::setUp();

        // [2026-10-28T22:00Z, 2026-10-29T22:00Z)
        $this->day = Window::kigaliDay('2026-10-29');
    }

    private function flight(string $aodbId, ?string $referenceTime = null): int
    {
        return DB::table('flights')->insertGetId([
            'aodb_id' => $aodbId,
            'reference_time' => $referenceTime,
            'first_seen_at' => '2026-10-27 00:00:00',
            'last_seen_at' => '2026-10-29 00:00:00',
        ]);
    }

    private function version(int $flightId, int $no, string $validFrom, ?string $referenceTime, array $extra = []): int
    {
        return DB::table('flight_versions')->insertGetId([
            'flight_id' => $flightId,
            'version_no' => $no,
            'valid_from' => $validFrom,
            'cause' => 'aodb',
            'reference_time' => $referenceTime,
            'reference_time_source' => $referenceTime === null ? null : 'sobt',
            'in_feed' => true,
            ...$extra,
        ]);
    }

    private function ids(?string $asOf): array
    {
        $rows = (new FlightQuery)->flightsInWindow($this->day, $asOf === null ? null : new DateTimeImmutable($asOf));

        return $rows->pluck('aodb_id')->all();
    }

    public function test_current_state_comes_from_flights_by_reference_time(): void
    {
        $this->flight('IN-LATE', '2026-10-29 21:59:59');
        $this->flight('IN-EARLY', '2026-10-28 22:00:00');
        $this->flight('OUT-END', '2026-10-29 22:00:00');
        $this->flight('NO-TIME');

        $this->assertSame(['IN-EARLY', 'IN-LATE'], $this->ids(null));
    }

    public function test_flight_moving_out_of_the_window_at_t(): void
    {
        $f = $this->flight('MOVED', '2026-10-30 08:00:00');
        $this->version($f, 1, '2026-10-28 10:00:00', '2026-10-29 08:00:00');
        $this->version($f, 2, '2026-10-29 06:00:00', '2026-10-30 08:00:00');

        $this->assertSame(['MOVED'], $this->ids('2026-10-29T05:59:59.999999Z'));
        $this->assertSame([], $this->ids('2026-10-29T06:00:00Z'));
        $this->assertSame([], $this->ids('2026-10-29T07:00:00Z'));
    }

    public function test_flight_first_seen_after_as_of_is_absent(): void
    {
        $f = $this->flight('LATECOMER', '2026-10-29 15:00:00');
        $this->version($f, 1, '2026-10-29 12:00:00', '2026-10-29 15:00:00');

        $this->assertSame([], $this->ids('2026-10-29T11:00:00Z'));
        $this->assertSame(['LATECOMER'], $this->ids('2026-10-29T12:00:00Z'));
    }

    public function test_carry_over_without_scheduled_time_is_placed_by_its_estimate(): void
    {
        // WB464A: no sobt, etot 00:45 Kigali = 2026-10-28T22:45Z.
        $etot = new DateTimeImmutable('2026-10-28T22:45:00Z');
        $ref = ReferenceTime::resolve('DEP', ['sobt' => null, 'etot' => $etot]);

        $f = $this->flight('WB464A');
        $this->version($f, 1, '2026-10-28 20:00:00', $ref->time->format('Y-m-d H:i:s'), [
            'flow' => 'DEP',
            'etot' => '2026-10-28 22:45:00',
            'reference_time_source' => $ref->source,
        ]);

        $rows = (new FlightQuery)->flightsInWindow($this->day, new DateTimeImmutable('2026-10-29T00:00:00Z'));

        $this->assertSame(['WB464A'], $rows->pluck('aodb_id')->all());
        $this->assertSame('etot', $rows[0]->reference_time_source);
        $this->assertNull($rows[0]->sobt);
    }

    public function test_equal_valid_from_higher_id_wins(): void
    {
        $a = $this->flight('TIE-OUT');
        $this->version($a, 1, '2026-10-29 06:00:00', '2026-10-29 08:00:00');
        $this->version($a, 2, '2026-10-29 06:00:00', '2026-10-30 08:00:00');

        $b = $this->flight('TIE-IN');
        $this->version($b, 1, '2026-10-29 06:00:00', '2026-10-30 09:00:00');
        $this->version($b, 2, '2026-10-29 06:00:00', '2026-10-29 09:00:00');

        $rows = (new FlightQuery)->flightsInWindow($this->day, new DateTimeImmutable('2026-10-29T07:00:00Z'));

        $this->assertSame(['TIE-IN'], $rows->pluck('aodb_id')->all());
        $this->assertSame(2, (int) $rows[0]->version_no);
    }

    public function test_as_of_query_uses_both_flight_versions_indexes(): void
    {
        $f = $this->flight('PLAN', '2026-10-29 10:00:00');
        $this->version($f, 1, '2026-10-28 10:00:00', '2026-10-29 10:00:00');
        $this->version($f, 2, '2026-10-29 03:00:00', '2026-10-29 10:00:00');

        DB::flushQueryLog();
        DB::enableQueryLog();
        (new FlightQuery)->flightsInWindow($this->day, new DateTimeImmutable('2026-10-29T04:00:00Z'));
        DB::disableQueryLog();
        ['query' => $sql, 'bindings' => $bindings] = DB::getQueryLog()[0];

        $keys = collect(DB::select('EXPLAIN '.$sql, $bindings))->pluck('key', 'table');

        $this->assertSame('flight_versions_reference_time_valid_from_index', $keys['v']);
        $this->assertSame('flight_versions_flight_id_valid_from_id_index', $keys['later']);
    }

    public function test_removed_from_feed_version_is_returned_with_in_feed_false(): void
    {
        $f = $this->flight('GONE', '2026-10-29 10:00:00');
        $this->version($f, 1, '2026-10-28 10:00:00', '2026-10-29 10:00:00');
        $this->version($f, 2, '2026-10-29 03:00:00', '2026-10-29 10:00:00', ['in_feed' => false]);

        $before = (new FlightQuery)->flightsInWindow($this->day, new DateTimeImmutable('2026-10-29T02:00:00Z'));
        $after = (new FlightQuery)->flightsInWindow($this->day, new DateTimeImmutable('2026-10-29T04:00:00Z'));

        $this->assertSame(1, (int) $before[0]->in_feed);
        $this->assertCount(1, $after);
        $this->assertSame(0, (int) $after[0]->in_feed);
        $this->assertSame(2, (int) $after[0]->version_no);
    }
}
