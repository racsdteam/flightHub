<?php

namespace Tests\Feature\Api;

use Tests\Feature\Collector\PollTestCase;

/**
 * hub.updatedAt (Batch 10b): when the hub observed the flight's version served, in the
 * identity group. A change moves it forward; an unchanged poll and a change to another
 * flight leave it; as_of answers give the time of the version returned.
 */
class UpdatedAtTest extends PollTestCase
{
    use ApiHelpers;

    private const DAY = 'flights?day=2026-10-29';

    private function updatedAt(string $key, string $aodbId, string $query = self::DAY): ?string
    {
        $flight = collect($this->api($query, $key)->assertOk()->json('data'))->firstWhere('id', $aodbId);
        $this->assertNotNull($flight, "{$aodbId} in {$query}");

        return $flight['hub']['updatedAt'];
    }

    public function test_it_moves_only_when_the_flight_changes(): void
    {
        $key = $this->consumerKey([]);
        $this->poll();

        $this->assertSame('2026-10-29T07:42:30Z', $this->updatedAt($key, self::KQ470), 'identity only: present');
        $this->assertSame('2026-10-29T07:42:30Z', $this->updatedAt($key, self::WB711));

        // 07:47:30: KQ470's estimate changes; WB711 does not.
        $this->travel(5)->minutes();
        $changed = $this->sample();
        self::record($changed, self::KQ470)->eldt = '2026-10-29T06:01:00Z';
        $this->poll($changed);
        $this->assertSame('2026-10-29T07:47:30Z', $this->updatedAt($key, self::KQ470), 'a change moves it forward');
        $this->assertSame('2026-10-29T07:42:30Z', $this->updatedAt($key, self::WB711), 'a change to another flight leaves it');
        $this->assertSame('2026-10-29T07:47:30Z', $this->api('flights/'.self::KQ470, $key)->json('data.hub.updatedAt'), 'the same in /flights/{id}');

        // 07:52:30: the same data again.
        $this->travel(5)->minutes();
        $this->poll($changed);
        $this->assertSame('2026-10-29T07:47:30Z', $this->updatedAt($key, self::KQ470), 'an unchanged poll leaves it');
    }

    public function test_as_of_gives_the_time_of_the_version_returned(): void
    {
        $key = $this->consumerKey(['times']);
        $this->poll();
        $this->travel(5)->minutes();
        $changed = $this->sample();
        self::record($changed, self::KQ470)->eldt = '2026-10-29T06:01:00Z';
        $this->poll($changed);

        $before = collect($this->api(self::DAY.'&as_of=2026-10-29T07:45:00Z', $key)->json('data'))->firstWhere('id', self::KQ470);
        $this->assertSame('2026-10-29T07:42:30Z', $before['hub']['updatedAt']);
        $this->assertNotSame('2026-10-29T06:01:00Z', $before['eldt'], 'the earlier version');

        $after = collect($this->api(self::DAY.'&as_of=2026-10-29T07:50:00Z', $key)->json('data'))->firstWhere('id', self::KQ470);
        $this->assertSame(['2026-10-29T07:47:30Z', '2026-10-29T06:01:00Z'], [$after['hub']['updatedAt'], $after['eldt']]);

        // first_seen in /changes carries the time of the version it produced.
        $firstSeen = collect($this->api('changes?cursor=0&limit=1000', $key)->json('data'))
            ->first(fn ($e) => $e['kind'] === 'first_seen' && $e['aodbId'] === self::KQ470);
        $this->assertSame('2026-10-29T07:42:30Z', $firstSeen['new']['hub']['updatedAt']);
    }
}
