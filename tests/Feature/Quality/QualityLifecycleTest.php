<?php

namespace Tests\Feature\Quality;

use App\Quality\FlagResolver;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Collector\PollTestCase;

/**
 * E2: open → cleared → reopened; "fixed" reset on reopen, "ignored" kept.
 */
class QualityLifecycleTest extends PollTestCase
{
    private function staleFlag(): object
    {
        return DB::table('quality_flags')->where(['flight_id' => $this->flight(self::WB711)->id, 'rule' => 'in_flight_stale'])->sole();
    }

    private function flagEvents(int $flagId): array
    {
        return DB::table('quality_flag_events')->where('flag_id', $flagId)->orderBy('id')
            ->get(['kind', 'from_value', 'to_value', 'actor'])->map(fn ($e) => [$e->kind, $e->from_value, $e->to_value, $e->actor])->all();
    }

    private function landed(bool $landed): \stdClass
    {
        $payload = $this->sample();
        if ($landed) {
            self::record($payload, self::WB711)->aibt = '2026-10-29T07:40:00Z';
        }

        return $payload;
    }

    public function test_still_true_updates_last_detected_without_event(): void
    {
        $this->poll();
        $first = $this->staleFlag();
        $this->travel(5)->minutes();

        $this->poll();

        $flag = $this->staleFlag();
        $this->assertSame('open', $flag->state);
        $this->assertSame('2026-10-29 07:47:30.000000', $flag->last_detected_at);
        $this->assertSame($first->first_detected_at, $flag->first_detected_at);
        // eibt 05:24Z; now 07:47:30Z → 143 minutes after the estimate (138 at the first poll).
        $this->assertSame(143, json_decode($flag->details, true)['minutes_after_estimate']);
        $this->assertSame(138, json_decode($first->details, true)['minutes_after_estimate']);
        $this->assertCount(1, $this->flagEvents($flag->id));
    }

    public function test_cleared_when_wb711_gets_aibt_and_reopened_when_it_returns(): void
    {
        $this->poll();
        $this->travel(5)->minutes();
        $this->poll($this->landed(true));

        $flag = $this->staleFlag();
        $this->assertSame('cleared', $flag->state);
        $this->assertSame('2026-10-29 07:47:30.000000', $flag->cleared_at);

        $this->travel(5)->minutes();
        $this->poll($this->landed(false));

        $flag = $this->staleFlag();
        $this->assertSame('open', $flag->state);
        $this->assertNull($flag->cleared_at);
        $this->assertSame([
            ['detected', null, 'open', null],
            ['cleared', 'open', 'cleared', null],
            ['reopened', 'cleared', 'open', null],
        ], $this->flagEvents($flag->id));
    }

    public function test_fixed_is_reset_on_reopen(): void
    {
        $this->poll();
        (new FlagResolver)->resolve($this->staleFlag()->id, 'fixed', 'Dany Kabalisa', 'asked the ops desk');
        $this->poll($this->landed(true));
        $this->poll($this->landed(false));

        $flag = $this->staleFlag();
        $this->assertSame('open', $flag->state);
        $this->assertNull($flag->resolution);
        $this->assertNull($flag->resolved_by);
        $this->assertSame(['resolved', 'fixed', null, null], array_slice($this->flagEvents($flag->id), -1)[0]);
    }

    public function test_ignored_is_kept_on_reopen(): void
    {
        $this->poll();
        (new FlagResolver)->resolve($this->staleFlag()->id, 'ignored', 'Dany Kabalisa');
        $this->poll($this->landed(true));
        $this->poll($this->landed(false));

        $flag = $this->staleFlag();
        $this->assertSame('open', $flag->state);
        $this->assertSame('ignored', $flag->resolution);
        $this->assertSame('Dany Kabalisa', $flag->resolved_by);
        $this->assertSame('reopened', array_slice($this->flagEvents($flag->id), -1)[0][0]);
    }

    public function test_open_flags_of_a_removed_flight_are_cleared(): void
    {
        $this->poll();
        $this->aodb->scriptById(fn (array $ids) => []);

        $this->poll(self::without($this->sample(), self::WB711));

        $flags = DB::table('quality_flags')->where('flight_id', $this->flight(self::WB711)->id)->get();
        $this->assertNotEmpty($flags);
        $this->assertTrue($flags->every(fn ($f) => $f->state === 'cleared'));
        $this->assertSame('flight removed from feed', DB::table('quality_flag_events')->where(['flag_id' => $flags[0]->id, 'kind' => 'cleared'])->value('note'));
    }

    public function test_evaluation_runs_only_after_an_ok_poll(): void
    {
        $this->poll('not json');

        $this->assertSame('invalid_payload', DB::table('polls')->value('outcome'));
        $this->assertSame(0, DB::table('quality_flags')->count());
    }
}
