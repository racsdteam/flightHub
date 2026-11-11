<?php

namespace Tests\Feature\Collector;

use App\Aodb\AodbResponse;
use Illuminate\Support\Facades\DB;

/**
 * Missing flights (C5): removal needs a time at least 60 min inside the
 * window AND /flights/v1/byid not returning the flight.
 */
class PollRemovalTest extends PollTestCase
{
    public function test_missing_flight_returned_by_byid_is_updated_and_stays_in_feed(): void
    {
        $this->poll();
        $moved = self::record($this->sample(), self::WB711);
        $moved->sibt = '2026-10-29T08:30:00Z';
        $this->aodb->scriptById(fn (array $ids) => [$moved]);

        $poll = $this->poll(self::without($this->sample(), self::WB711));

        $this->assertSame([[self::WB711]], $this->aodb->byIdCalls);
        $this->assertSame(0, (int) $poll->flights_removed);
        $this->assertSame(1, (int) $poll->flights_changed);
        $flight = $this->flight(self::WB711);
        $this->assertSame(1, (int) $flight->in_feed);
        $this->assertSame('2026-10-29 08:30:00', $flight->reference_time);
        $this->assertSame(['sibt'], array_column($this->events(self::WB711, 'field_changed'), 'field'));
        $this->assertSame([], $this->events(self::WB711, 'removed_from_feed'));
    }

    public function test_missing_flight_not_returned_by_byid_is_removed_never_deleted(): void
    {
        $this->poll();
        $this->aodb->scriptById(fn (array $ids) => []);

        $poll = $this->poll(self::without($this->sample(), self::WB711));

        $this->assertSame(1, (int) $poll->flights_removed);
        $flight = $this->flight(self::WB711);
        $this->assertSame(0, (int) $flight->in_feed);
        $this->assertSame('2026-10-29 07:42:30.000000', $flight->removed_from_feed_at);
        $versions = $this->versions(self::WB711);
        $this->assertCount(2, $versions);
        $this->assertSame(0, (int) $versions[1]->in_feed);
        $this->assertSame($versions[0]->record, $versions[1]->record);
        $removed = $this->events(self::WB711, 'removed_from_feed');
        $this->assertCount(1, $removed);
        $this->assertSame((int) $versions[1]->id, (int) $removed[0]->version_id);

        // The hub's own conclusion, with the byid answer that did not contain the flight as evidence.
        $this->assertSame('system', $removed[0]->source);
        $this->assertSame('system', $versions[1]->cause);
        $this->assertSame('aodb', $versions[0]->cause);
        $evidence = DB::table('raw_responses')->where('endpoint', '/flights/v1/byid')->sole();
        $this->assertSame('raw_responses:'.$evidence->id, $removed[0]->source_ref);
        $this->assertSame([], json_decode(gzdecode($evidence->body), true)['flights']);
        $this->assertNull($evidence->request_window_from);
    }

    public function test_removed_flight_that_reappears_returns_to_feed(): void
    {
        $this->poll();
        $this->aodb->scriptById(fn (array $ids) => []);
        $this->poll(self::without($this->sample(), self::WB711));
        $this->travel(1)->minutes();

        $poll = $this->poll($this->sample());

        $this->assertSame(1, (int) $poll->flights_returned);
        $this->assertSame(0, (int) $poll->flights_changed);
        $flight = $this->flight(self::WB711);
        $this->assertSame(1, (int) $flight->in_feed);
        $this->assertNull($flight->removed_from_feed_at);
        $this->assertCount(3, $this->versions(self::WB711));
        $this->assertSame(1, (int) $this->versions(self::WB711)[2]->in_feed);
        $returned = $this->events(self::WB711, 'returned_to_feed');
        $this->assertCount(1, $returned);
        $this->assertSame('system', $returned[0]->source);
        $this->assertSame('system', $this->versions(self::WB711)[2]->cause);
        $this->assertSame('aodb', $this->events(self::WB711, 'first_seen')[0]->source);
    }

    public function test_return_with_field_changes_is_one_aodb_version(): void
    {
        $this->poll();
        $this->aodb->scriptById(fn (array $ids) => []);
        $this->poll(self::without($this->sample(), self::WB711));

        $payload = $this->sample();
        self::record($payload, self::WB711)->status = 'TERMINATED';
        $this->poll($payload);

        $versions = $this->versions(self::WB711);
        $this->assertCount(3, $versions);
        $this->assertSame('aodb', $versions[2]->cause);
        $events = collect($this->events(self::WB711))->where('version_id', $versions[2]->id);
        $this->assertSame([['field_changed', 'aodb'], ['returned_to_feed', 'system']],
            $events->map(fn ($e) => [$e->kind, $e->source])->values()->all());
    }

    public function test_byid_answers_are_stored_per_endpoint_and_reused_when_identical(): void
    {
        $this->poll();
        $this->aodb->scriptById(fn (array $ids) => []);
        $this->poll(self::without($this->sample(), self::WB711));
        $this->travel(5)->minutes();

        $poll = $this->poll(self::without(self::without($this->sample(), self::WB711), self::KQ470));

        $this->assertSame(1, (int) $poll->flights_removed);
        $byid = DB::table('raw_responses')->where('endpoint', '/flights/v1/byid')->get();
        $this->assertCount(1, $byid, 'identical byid answer: the stored row is reused');
        $this->assertSame('raw_responses:'.$byid[0]->id, $this->events(self::KQ470, 'removed_from_feed')[0]->source_ref);
        $this->assertSame(3, DB::table('raw_responses')->where('endpoint', '/flights/v1/airport')->count());
    }

    public function test_byid_answer_with_the_moved_flight_is_stored(): void
    {
        $this->poll();
        $moved = self::record($this->sample(), self::WB711);
        $moved->sibt = '2026-10-29T08:30:00Z';
        $this->aodb->scriptById(fn (array $ids) => [$moved]);

        $this->poll(self::without($this->sample(), self::WB711));

        $raw = DB::table('raw_responses')->where('endpoint', '/flights/v1/byid')->sole();
        $this->assertSame('2026-10-29T08:30:00Z', json_decode(gzdecode($raw->body))->flights[0]->sibt);
    }

    public function test_removal_pre_filter_uses_the_in_feed_reference_time_index(): void
    {
        $this->poll();
        $this->aodb->scriptById(fn (array $ids) => []);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->poll(self::without($this->sample(), self::WB711));
        DB::disableQueryLog();

        $query = collect(DB::getQueryLog())->first(fn ($q) => str_contains($q['query'], 'flights_in_feed_reference_time_index'));
        $this->assertNotNull($query);
        $plan = DB::select('EXPLAIN '.$query['query'], $query['bindings']);
        $this->assertSame('flights_in_feed_reference_time_index', $plan[0]->key);
    }

    public function test_byid_error_concludes_nothing(): void
    {
        $this->poll();
        $this->aodb->scriptById(fn (array $ids) => AodbResponse::fromFailure(AodbResponse::HTTP_ERROR, '/flights/v1/byid', 503, null));

        $poll = $this->poll(self::without($this->sample(), self::WB711));

        $this->assertSame('ok', $poll->outcome);
        $this->assertSame(0, (int) $poll->flights_removed);
        $this->assertSame(1, (int) $this->flight(self::WB711)->in_feed);
        $this->assertCount(1, $this->events(self::WB711));
        $this->assertSame([['code' => 'byid_failed', 'detail' => '503']], json_decode($poll->warnings, true));
    }

    public function test_missing_flight_outside_the_margin_aged_out(): void
    {
        // Lands 30 min after the window start (2026-10-27T22:00Z): inside, but not 60 min inside.
        $first = $this->sample();
        $edge = clone self::record($first, self::WB711);
        $edge->id = 'edge-0000-0000-0000-000000000001';
        $edge->sibt = '2026-10-27T22:30:00Z';
        $edge->eibt = '2026-10-27T22:25:00Z';
        $edge->eldt = '2026-10-27T22:20:00Z';
        $first->flights[] = $edge;
        $this->poll($first);

        $poll = $this->poll($this->sample());

        $this->assertSame([], $this->aodb->byIdCalls);
        $this->assertSame(0, (int) $poll->flights_removed);
        $this->assertSame(1, (int) $this->flight($edge->id)->in_feed);
        $this->assertCount(1, $this->events($edge->id));
        $this->assertSame(1, (int) $poll->content_changed);
        $this->assertSame(0, (int) $poll->events_written);
    }

    public function test_byid_is_asked_in_configured_chunks(): void
    {
        $this->assertSame(50, config('aodb.byid_chunk'));
        config(['aodb.byid_chunk' => 20]);
        $this->poll();
        $this->aodb->scriptById(fn (array $ids) => []);

        $empty = $this->sample();
        $empty->flights = [];
        $poll = $this->poll($empty);

        $this->assertSame([20, 20, 11], array_map('count', $this->aodb->byIdCalls));
        $this->assertSame(51, (int) $poll->flights_removed);
        $this->assertSame(0, DB::table('flights')->where('in_feed', true)->count());
    }
}
