<?php

namespace Tests\Feature\Collector;

use Illuminate\Support\Facades\DB;

/**
 * E1 and E3: rotations (hub-owned). The expectations are the batch's.
 */
class RotationTest extends PollTestCase
{
    private const WB434_DEP = 'd7e33768-bfbc-4403-9814-dc153b601843';

    private const WB464_DEP = 'ea186943-1aeb-4915-bf00-29344930ab4c';

    private const WB202_DEP = 'a67615c9-d505-4769-a3e3-4374aabb6518';

    /**
     * @return list<string> "ARR flight → DEP flight (source)"
     */
    private function links(): array
    {
        return DB::table('flights as a')
            ->join('flights as d', 'd.id', '=', 'a.rotation_next_flight_id')
            ->orderBy('a.full_flight_number')
            ->get(['a.full_flight_number as arr', 'a.aodb_id as arr_id', 'd.full_flight_number as dep', 'd.aodb_id as dep_id', 'a.rotation_source as source'])
            ->map(fn ($l) => "{$l->arr} {$l->arr_id} → {$l->dep} {$l->dep_id} ({$l->source})")
            ->all();
    }

    private function rotationEvents(?string $aodbId = null): array
    {
        return DB::table('change_events')
            ->join('flights', 'flights.id', '=', 'change_events.flight_id')
            ->whereIn('change_events.field', ['hub.rotationNext', 'hub.rotationPrev'])
            ->when($aodbId, fn ($q) => $q->where('flights.aodb_id', $aodbId))
            ->orderBy('change_events.id')
            ->get(['flights.full_flight_number', 'change_events.field', 'change_events.old_value', 'change_events.new_value', 'change_events.source'])
            ->map(fn ($e) => [$e->full_flight_number, $e->field, json_decode((string) $e->old_value), json_decode((string) $e->new_value), $e->source])
            ->all();
    }

    public function test_sample_links_exactly_wb464_to_wb434_and_kq478d_to_the_morning_kq479(): void
    {
        $this->poll();
        $wb434 = self::WB434_DEP;

        $this->assertSame([
            'KQ478D '.self::KQ478D.' → KQ479 '.self::KQ479_MORNING.' (registration)',
            'WB464 '.self::WB464_ARR.' → WB434 '.$wb434.' (registration)',
        ], $this->links());

        // The departure side points back; nothing else is linked.
        $this->assertSame((int) $this->flight(self::WB464_ARR)->id, (int) $this->flight($wb434)->rotation_prev_flight_id);
        $this->assertSame((int) $this->flight(self::KQ478D)->id, (int) $this->flight(self::KQ479_MORNING)->rotation_prev_flight_id);
        $this->assertSame(2, DB::table('flights')->whereNotNull('rotation_prev_flight_id')->count());
        $this->assertSame(4, DB::table('flights')->whereNotNull('rotation_source')->count());

        // Same version as first_seen; events with aodb_ids, source system.
        $this->assertCount(1, $this->versions(self::WB464_ARR));
        $this->assertSame(['WB464', 'hub.rotationNext', null, $wb434, 'system'], $this->rotationEvents(self::WB464_ARR)[0]);
        $this->assertSame(['WB434', 'hub.rotationPrev', null, self::WB464_ARR, 'system'], $this->rotationEvents($wb434)[0]);
        $this->assertCount(4, $this->rotationEvents());
    }

    public function test_linked_id_beats_registration(): void
    {
        $payload = $this->sample();
        self::record($payload, self::WB464_ARR)->linkedId = self::WB464_DEP;

        $this->poll($payload);

        $wb434 = self::WB434_DEP;
        $arr = $this->flight(self::WB464_ARR);
        $this->assertSame((int) $this->flight(self::WB464_DEP)->id, (int) $arr->rotation_next_flight_id);
        $this->assertSame('aodb', $arr->rotation_source);
        $this->assertSame('aodb', $this->flight(self::WB464_DEP)->rotation_source);
        $this->assertNull($this->flight($wb434)->rotation_prev_flight_id);
        $this->assertSame(['WB464', 'hub.rotationNext', null, self::WB464_DEP, 'aodb'], $this->rotationEvents(self::WB464_ARR)[0]);
    }

    public function test_a_registration_change_moves_the_link_and_a_lost_link_is_an_event_with_null(): void
    {
        $this->poll();
        $wb434 = self::WB434_DEP;
        $wb202 = self::WB202_DEP;

        // Tail swap: WB434 gets another aircraft, WB202 gets 9XRWT.
        $payload = $this->sample();
        self::record($payload, $wb434)->reg = '9XRWZ';
        self::record($payload, $wb202)->reg = '9XRWT';
        $this->poll($payload);

        $this->assertSame((int) $this->flight($wb202)->id, (int) $this->flight(self::WB464_ARR)->rotation_next_flight_id);
        $this->assertSame([
            ['WB464', 'hub.rotationNext', null, $wb434, 'system'],
            ['WB464', 'hub.rotationNext', $wb434, $wb202, 'system'],
        ], $this->rotationEvents(self::WB464_ARR));
        $this->assertSame(['WB434', 'hub.rotationPrev', self::WB464_ARR, null, 'system'], $this->rotationEvents($wb434)[1]);
        $this->assertSame(['WB202', 'hub.rotationPrev', null, self::WB464_ARR, 'system'], $this->rotationEvents($wb202)[0]);
        $this->assertNull($this->flight($wb434)->rotation_source);

        // 9XRWT gone from every later departure: the link is removed.
        $payload = $this->sample();
        self::record($payload, $wb434)->reg = '9XRWZ';
        $this->poll($payload);

        $this->assertNull($this->flight(self::WB464_ARR)->rotation_next_flight_id);
        $this->assertSame(['WB464', 'hub.rotationNext', $wb202, null, 'system'], array_slice($this->rotationEvents(self::WB464_ARR), -1)[0]);
        $versions = $this->versions(self::WB464_ARR);
        $this->assertSame(['system', null], [$versions[2]->cause, $versions[2]->rotation_next_flight_id]);
    }

    public function test_rotation_links_survive_unrelated_versions(): void
    {
        $this->poll();
        $payload = $this->sample();
        self::record($payload, self::WB464_ARR)->pkg = 'A7';

        $this->poll($payload);

        $versions = $this->versions(self::WB464_ARR);
        $this->assertCount(2, $versions);
        $this->assertSame('aodb', $versions[1]->cause);
        $this->assertSame($versions[0]->rotation_next_flight_id, $versions[1]->rotation_next_flight_id);
        $this->assertCount(1, $this->rotationEvents(self::WB464_ARR));
    }
}
