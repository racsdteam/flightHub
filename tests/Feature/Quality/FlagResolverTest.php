<?php

namespace Tests\Feature\Quality;

use App\Collector\Poller;
use App\Quality\FlagResolver;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Tests\Feature\Collector\PollTestCase;

/**
 * E3: resolutions, same_as only on duplicates, its version and event, reversal, lock.
 */
class FlagResolverTest extends PollTestCase
{
    private const TK606_ARR_IST = 'bb7871bd-6796-4a27-a1fa-52dccc94b3a2';

    private function duplicateFlag(): object
    {
        return DB::table('quality_flags')->where(['flight_id' => $this->flight(self::TK606_ARR_TK)->id, 'rule' => 'duplicate_suspected'])->sole();
    }

    private function flagOf(string $aodbId, string $rule): object
    {
        return DB::table('quality_flags')->where(['flight_id' => $this->flight($aodbId)->id, 'rule' => $rule])->first();
    }

    public function test_reported_records_who_when_and_a_note(): void
    {
        $this->poll();
        $flag = $this->flagOf(self::KQ470, 'format_invalid');

        $resolved = (new FlagResolver)->resolve($flag->id, 'reported', 'Dany Kabalisa', 'mail to Innov\'ATM support');

        $this->assertSame(['reported', 'Dany Kabalisa', '2026-10-29 07:42:30.000000', 'mail to Innov\'ATM support', 'open'],
            [$resolved->resolution, $resolved->resolved_by, $resolved->resolved_at, $resolved->resolution_note, $resolved->state]);
        $event = DB::table('quality_flag_events')->where(['flag_id' => $flag->id, 'kind' => 'resolved'])->sole();
        $this->assertSame([null, 'reported', 'Dany Kabalisa', 'mail to Innov\'ATM support'], [$event->from_value, $event->to_value, $event->actor, $event->note]);
    }

    public function test_only_documented_resolutions_and_an_actor_are_accepted(): void
    {
        $this->poll();
        $flag = $this->flagOf(self::KQ470, 'format_invalid');

        foreach ([['closed', 'Dany'], ['fixed', '  '], ['fixed', str_repeat('x', 101)]] as [$resolution, $actor]) {
            try {
                (new FlagResolver)->resolve($flag->id, $resolution, $actor);
                $this->fail("{$resolution} / '{$actor}' was accepted");
            } catch (InvalidArgumentException) {
            }
        }
        $this->assertSame(0, DB::table('quality_flag_events')->where('kind', 'resolved')->count());
    }

    public function test_same_as_is_refused_on_anything_but_a_duplicate(): void
    {
        $this->poll();

        $this->expectException(InvalidArgumentException::class);
        (new FlagResolver)->resolve($this->flagOf(self::KQ470, 'format_invalid')->id, 'same_as', 'Dany Kabalisa');
    }

    public function test_same_as_writes_a_manual_version_and_a_hub_same_as_event(): void
    {
        $this->poll();
        $flag = $this->duplicateFlag();
        $this->travel(1)->minutes();

        (new FlagResolver)->resolve($flag->id, 'same_as', 'Dany Kabalisa', 'one TK606 arrival');

        $flight = $this->flight(self::TK606_ARR_TK);
        $other = $this->flight(self::TK606_ARR_IST);
        $this->assertSame((int) $other->id, (int) $flight->same_as_flight_id);

        $versions = $this->versions(self::TK606_ARR_TK);
        $this->assertCount(2, $versions);
        $this->assertSame(['manual', (int) $other->id, null], [$versions[1]->cause, (int) $versions[1]->same_as_flight_id, $versions[1]->poll_id]);
        $this->assertSame($versions[0]->record, $versions[1]->record);
        $this->assertSame((int) $versions[1]->id, (int) $flight->current_version_id);

        $event = DB::table('change_events')->where('field', 'hub.sameAs')->sole();
        $this->assertSame(['field_changed', 'manual', 'quality_flags:'.$flag->id, null, json_encode(self::TK606_ARR_IST)],
            [$event->kind, $event->source, $event->source_ref, $event->old_value, $event->new_value]);
        $this->assertSame((int) $versions[1]->id, (int) $event->version_id);

        $flagEvent = DB::table('quality_flag_events')->where(['flag_id' => $flag->id, 'kind' => 'resolved'])->sole();
        $this->assertSame([null, 'same_as', 'Dany Kabalisa'], [$flagEvent->from_value, $flagEvent->to_value, $flagEvent->actor]);
    }

    public function test_removing_same_as_reverses_it_the_same_way(): void
    {
        $this->poll();
        $flag = $this->duplicateFlag();
        (new FlagResolver)->resolve($flag->id, 'same_as', 'Dany Kabalisa');
        $this->travel(1)->minutes();

        (new FlagResolver)->resolve($flag->id, null, 'Dany Kabalisa', 'not the same after all');

        $this->assertNull($this->flight(self::TK606_ARR_TK)->same_as_flight_id);
        $versions = $this->versions(self::TK606_ARR_TK);
        $this->assertCount(3, $versions);
        $this->assertSame(['manual', null], [$versions[2]->cause, $versions[2]->same_as_flight_id]);
        $events = DB::table('change_events')->where('field', 'hub.sameAs')->orderBy('id')->get();
        $this->assertSame([json_encode(self::TK606_ARR_IST), null], [$events[1]->old_value, $events[1]->new_value]);
        $resolved = $this->duplicateFlag();
        $this->assertSame([null, null], [$resolved->resolution, $resolved->resolved_by]);
        $this->assertSame(['same_as', null], [
            DB::table('quality_flag_events')->where(['flag_id' => $flag->id, 'kind' => 'resolved'])->orderByDesc('id')->value('from_value'),
            DB::table('quality_flag_events')->where(['flag_id' => $flag->id, 'kind' => 'resolved'])->orderByDesc('id')->value('to_value'),
        ]);
    }

    public function test_same_as_survives_later_poll_versions(): void
    {
        $this->poll();
        (new FlagResolver)->resolve($this->duplicateFlag()->id, 'same_as', 'Dany Kabalisa');
        $payload = $this->sample();
        self::record($payload, self::TK606_ARR_TK)->eldt = '2026-10-29T22:45:00Z';

        $this->poll($payload);

        $versions = $this->versions(self::TK606_ARR_TK);
        $this->assertCount(3, $versions);
        $this->assertSame('aodb', $versions[2]->cause);
        $this->assertSame((int) $this->flight(self::TK606_ARR_IST)->id, (int) $versions[2]->same_as_flight_id);
    }

    public function test_same_as_is_written_under_the_change_events_lock(): void
    {
        $this->poll();
        $queries = [];
        DB::listen(function (QueryExecuted $q) use (&$queries) {
            $queries[] = $q->sql;
        });

        (new FlagResolver)->resolve($this->duplicateFlag()->id, 'same_as', 'Dany Kabalisa');

        $lock = array_search('SELECT GET_LOCK(?, ?) AS l', $queries, true);
        $release = array_search('SELECT RELEASE_LOCK(?) AS l', $queries, true);
        $event = collect($queries)->search(fn ($sql) => str_starts_with($sql, 'insert into `change_events`'));
        $this->assertNotFalse($lock);
        $this->assertTrue($lock < $event && $event < $release);
    }

    public function test_busy_lock_refuses_same_as_and_writes_nothing(): void
    {
        $this->poll();
        $other = DB::connection('mariadb_ddl');
        $other->selectOne('SELECT GET_LOCK(?, 0) AS l', [Poller::LOCK]);

        try {
            (new FlagResolver(lockTimeout: 0))->resolve($this->duplicateFlag()->id, 'same_as', 'Dany Kabalisa');
            $this->fail('same_as was written without the lock');
        } catch (RuntimeException) {
        } finally {
            $other->selectOne('SELECT RELEASE_LOCK(?) AS l', [Poller::LOCK]);
        }

        $this->assertNull($this->flight(self::TK606_ARR_TK)->same_as_flight_id);
        $this->assertNull($this->duplicateFlag()->resolution);
        $this->assertSame(0, DB::table('change_events')->where('field', 'hub.sameAs')->count());
    }
}
