<?php

namespace Tests\Feature\Reference;

use App\Collector\CorrectionReapplier;
use App\Collector\Poller;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * E4 (stand_unknown) and E5 (hub:reapply-corrections; since Batch 11b it also runs the
 * quality rules, as after a poll).
 */
class StandsAndReapplyTest extends ReferenceDataTestCase
{
    private function openStandFlags(): array
    {
        return DB::table('quality_flags')->join('flights', 'flights.id', '=', 'quality_flags.flight_id')
            ->where(['rule' => 'stand_unknown', 'state' => 'open'])
            ->pluck('flights.aodb_id')->all();
    }

    public function test_stand_unknown_is_inactive_while_the_stands_table_is_empty(): void
    {
        $this->poll();

        $this->assertSame([], $this->openStandFlags());
    }

    public function test_stand_unknown_flags_a_pkg_not_in_the_active_stands(): void
    {
        $this->stand('A2');
        $this->stand('A5');

        $this->poll();

        // pkg in the sample: A2 (ET807), A5 (WB711), A2B (KQ478D).
        $this->assertSame([self::KQ478D], $this->openStandFlags());
        $flag = DB::table('quality_flags')->where('rule', 'stand_unknown')->sole();
        $this->assertSame(['pkg' => 'A2B'], json_decode($flag->details, true));
    }

    public function test_reapply_corrects_after_a_new_correction_row_and_a_second_run_writes_nothing(): void
    {
        $this->poll();
        $id = DB::connection('mariadb_ddl')->table('airport_code_corrections')->insertGetId([
            'aodb_value' => 'TK', 'corrected_iata' => 'IST', 'corrected_icao' => 'LTFM',
            'reason' => 'TEST ONLY', 'reviewed_by' => 'TEST ONLY', 'reviewed_on' => '2026-11-11', 'active' => true,
        ]);

        $this->artisan('hub:reapply-corrections')
            ->expectsOutputToContain('1 flights corrected, 2 correction events')
            ->assertExitCode(0);

        $flight = $this->flight(self::TK606_ARR_TK);
        $this->assertSame(['TK', 'IST', null, 'LTFM'], [$flight->adep_iata_aodb, $flight->adep_iata, $flight->adep_icao_aodb, $flight->adep_icao]);
        $versions = $this->versions(self::TK606_ARR_TK);
        $this->assertSame(['correction', null], [$versions[1]->cause, $versions[1]->poll_id]);
        $this->assertSame($versions[0]->record, $versions[1]->record);
        $events = DB::table('change_events')->where('version_id', $versions[1]->id)->orderBy('field')->get();
        $this->assertSame([['adepIata', '"TK"', '"IST"'], ['adepIcao', null, '"LTFM"']], $events->map(fn ($e) => [$e->field, $e->old_value, $e->new_value])->all());
        $this->assertTrue($events->every(fn ($e) => $e->source === 'correction' && $e->source_ref === "airport_code_corrections:{$id}"));

        $before = [DB::table('flight_versions')->count(), DB::table('change_events')->count()];
        $this->artisan('hub:reapply-corrections')->expectsOutputToContain('0 flights corrected')->assertExitCode(0);
        $this->assertSame($before, [DB::table('flight_versions')->count(), DB::table('change_events')->count()]);
    }

    public function test_reapply_fills_types_from_a_new_aircraft_types_row(): void
    {
        $this->poll();
        $this->aircraftType('WB', 'DH8', 'DH8D', 'Dash 8-400 (TEST)', 'M');

        $this->artisan('hub:reapply-corrections')
            ->expectsOutputToContain('8 flights corrected, 24 correction events')
            ->assertExitCode(0);
    }

    public function test_reapply_runs_the_quality_rules_so_type_missing_follows_without_a_poll(): void
    {
        $this->poll();
        $open = fn () => DB::table('quality_flags')->where(['rule' => 'type_missing', 'state' => 'open'])->count();
        $this->assertSame(11, $open());
        $this->aircraftType('WB', 'DH8', 'DH8D', 'Dash 8-400 (TEST)', 'M');

        $this->travel(2)->minutes();
        $this->artisan('hub:reapply-corrections')
            ->expectsOutputToContain('8 flights corrected, 24 correction events; quality:')
            ->assertExitCode(0);

        $this->assertSame(1, DB::table('polls')->count(), 'no poll ran');
        $this->assertSame(3, $open());
        // The WB DH8 flags are cleared at the re-apply's time, with a cleared event each.
        $cleared = DB::table('quality_flags')->join('flights', 'flights.id', '=', 'quality_flags.flight_id')
            ->where(['rule' => 'type_missing', 'state' => 'cleared'])->get(['flights.airline_iata', 'flights.ac_type_iata', 'quality_flags.id', 'quality_flags.cleared_at']);
        $this->assertCount(8, $cleared);
        $this->assertTrue($cleared->every(fn ($f) => $f->airline_iata === 'WB' && $f->ac_type_iata === 'DH8' && str_starts_with($f->cleared_at, '2026-10-29 07:44:30')));
        $this->assertSame(8, DB::table('quality_flag_events')->where('kind', 'cleared')->whereIn('flag_id', $cleared->pluck('id'))->count());
    }

    public function test_reapply_runs_the_quality_rules_so_new_stands_flag_a_pkg_without_a_poll(): void
    {
        $this->poll();
        $this->assertSame([], $this->openStandFlags());
        $this->stand('A2');
        $this->stand('A5');

        $this->artisan('hub:reapply-corrections')
            ->expectsOutputToContain('0 flights corrected, 0 correction events; quality:')
            ->assertExitCode(0);

        $this->assertSame(1, DB::table('polls')->count(), 'no poll ran');
        $this->assertSame([self::KQ478D], $this->openStandFlags());
        $this->assertSame(['pkg' => 'A2B'], json_decode(DB::table('quality_flags')->where('rule', 'stand_unknown')->value('details'), true));
    }

    public function test_reapply_on_a_stale_feed_corrects_but_leaves_the_quality_rules_to_the_next_ok_poll(): void
    {
        $this->poll();
        $this->aircraftType('WB', 'DH8', 'DH8D', 'Dash 8-400 (TEST)', 'M');
        $open = fn () => DB::table('quality_flags')->where(['rule' => 'type_missing', 'state' => 'open'])->count();
        $before = [DB::table('quality_flags')->count(), DB::table('quality_flag_events')->count()];

        // 16 minutes after the last ok poll the feed is stale (D-037): the clock rules would
        // flag times the hub has not received yet, so no rule runs.
        $this->travel(16)->minutes();
        $this->artisan('hub:reapply-corrections')
            ->expectsOutputToContain('8 flights corrected, 24 correction events; quality rules not run (the feed is stale; the next ok poll runs them).')
            ->assertExitCode(0);

        $this->assertSame($before, [DB::table('quality_flags')->count(), DB::table('quality_flag_events')->count()]);
        $this->assertSame(11, $open());
        $this->poll();
        $this->assertSame(3, $open());
    }

    public function test_a_deactivated_correction_returns_the_aodb_value(): void
    {
        $this->poll();
        DB::connection('mariadb_ddl')->table('airport_code_corrections')->where('aodb_value', 'NB0')->update(['active' => false]);

        try {
            $this->artisan('hub:reapply-corrections')->expectsOutputToContain('2 flights corrected, 4 correction events')->assertExitCode(0);

            $flight = $this->flight(self::KQ470);
            $this->assertSame(['NB0', null], [$flight->adep_iata, $flight->adep_icao]);
            $event = DB::table('change_events')->where(['flight_id' => $flight->id, 'field' => 'adepIata'])->orderByDesc('id')->first();
            $this->assertSame(['"NBO"', '"NB0"', 'correction', null], [$event->old_value, $event->new_value, $event->source, $event->source_ref]);
        } finally {
            DB::connection('mariadb_ddl')->table('airport_code_corrections')->where('aodb_value', 'NB0')->update(['active' => true]);
        }
    }

    public function test_reapply_refuses_while_the_change_events_lock_is_held(): void
    {
        $this->poll();
        $this->aircraftType('WB', 'DH8', 'DH8D', null, null);
        $other = DB::connection('mariadb_ddl');
        $other->selectOne('SELECT GET_LOCK(?, 0) AS l', [Poller::LOCK]);

        try {
            (new CorrectionReapplier(lockTimeout: 0))->run();
            $this->fail('hub:reapply-corrections wrote without the change events lock');
        } catch (RuntimeException) {
        } finally {
            $other->selectOne('SELECT RELEASE_LOCK(?) AS l', [Poller::LOCK]);
        }

        $this->assertNull($this->dh8Type());
    }

    private function dh8Type(): ?string
    {
        return DB::table('flights')->where(['airline_iata' => 'WB', 'ac_type_iata' => 'DH8'])->value('ac_type_icao');
    }
}
