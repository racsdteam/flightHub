<?php

namespace App\Quality;

use App\Flights\Window;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Runs the rules after every ok poll, in its own transaction, over the
 * in-feed flights of the poll window, and keeps quality_flags up to date.
 *
 * Lifecycle (state is the hub's): new condition → open + detected; still
 * true → last_detected_at and details only; no longer true → cleared;
 * true again → open + reopened, and a resolution "fixed" is reset (the fix
 * did not hold) while other resolutions are kept. Open flags of flights that
 * left the feed are cleared. Flags are never deleted.
 */
final class QualityEvaluator
{
    /**
     * @return array{evaluated: int, detected: int, cleared: int, reopened: int}
     */
    public function evaluate(Window $window, CarbonImmutable $now): array
    {
        return DB::transaction(fn () => $this->run($window, $now->utc()));
    }

    private function run(Window $window, CarbonImmutable $now): array
    {
        $at = $now->format('Y-m-d H:i:s.u');
        $counts = ['evaluated' => 0, 'detected' => 0, 'cleared' => 0, 'reopened' => 0];
        $rules = new FlightRules(config('quality'));

        $flights = DB::table('flights')
            ->where('in_feed', true)
            ->where('reference_time', '>=', $window->from->format('Y-m-d H:i:s'))
            ->where('reference_time', '<', $window->to->format('Y-m-d H:i:s'))
            ->orderBy('id')
            ->get();
        $counts['evaluated'] = $flights->count();

        $duplicates = $rules->duplicates($flights);
        $existing = DB::table('quality_flags')
            ->whereIn('flight_id', $flights->pluck('id')->all())
            ->get()
            ->groupBy('flight_id');

        foreach ($flights as $flight) {
            $conditions = [];
            foreach ([...$rules->check($flight, $now), ...($duplicates[$flight->id] ?? [])] as $condition) {
                $conditions[$condition->key()] = $condition;
            }
            $flags = ($existing[$flight->id] ?? collect())->keyBy('flag_key');

            foreach ($conditions as $key => $condition) {
                $flag = $flags[$key] ?? null;
                if ($flag === null) {
                    $this->open($flight->id, $condition, $at);
                    $counts['detected']++;
                } elseif ($flag->state === 'open') {
                    $this->seen($flag->id, $condition, $at);
                } else {
                    $this->reopen($flag, $condition, $at);
                    $counts['reopened']++;
                }
            }

            foreach ($flags as $key => $flag) {
                if ($flag->state === 'open' && ! isset($conditions[$key])) {
                    $this->clear($flag->id, $at, null);
                    $counts['cleared']++;
                }
            }
        }

        // Flights that left the feed: their open flags are cleared.
        $gone = DB::table('quality_flags')
            ->join('flights', 'flights.id', '=', 'quality_flags.flight_id')
            ->where('flights.in_feed', false)
            ->where('quality_flags.state', 'open')
            ->pluck('quality_flags.id');
        foreach ($gone as $flagId) {
            $this->clear($flagId, $at, 'flight removed from feed');
            $counts['cleared']++;
        }

        return $counts;
    }

    private function open(int $flightId, Condition $condition, string $at): void
    {
        $id = DB::table('quality_flags')->insertGetId([
            'flight_id' => $flightId,
            'rule' => $condition->rule,
            'field' => $condition->field,
            'related_flight_id' => $condition->related?->id,
            'flag_key' => $condition->key(),
            'state' => 'open',
            'details' => self::json($condition->details),
            'first_detected_at' => $at,
            'last_detected_at' => $at,
            'created_at' => substr($at, 0, 19),
            'updated_at' => substr($at, 0, 19),
        ]);
        self::event($id, 'detected', null, 'open', null, null, $at);
    }

    private function seen(int $flagId, Condition $condition, string $at): void
    {
        DB::table('quality_flags')->where('id', $flagId)->update([
            'details' => self::json($condition->details),
            'last_detected_at' => $at,
            'updated_at' => substr($at, 0, 19),
        ]);
    }

    private function reopen(object $flag, Condition $condition, string $at): void
    {
        $reset = $flag->resolution === 'fixed';
        DB::table('quality_flags')->where('id', $flag->id)->update([
            'state' => 'open',
            'details' => self::json($condition->details),
            'last_detected_at' => $at,
            'cleared_at' => null,
            'updated_at' => substr($at, 0, 19),
        ] + ($reset ? ['resolution' => null, 'resolved_by' => null, 'resolved_at' => null, 'resolution_note' => null] : []));
        self::event($flag->id, 'reopened', 'cleared', 'open', null, null, $at);

        if ($reset) {
            self::event($flag->id, 'resolved', 'fixed', null, null, 'resolution "fixed" reset: the condition returned', $at);
        }
    }

    private function clear(int $flagId, string $at, ?string $note): void
    {
        DB::table('quality_flags')->where('id', $flagId)->update([
            'state' => 'cleared',
            'cleared_at' => $at,
            'updated_at' => substr($at, 0, 19),
        ]);
        self::event($flagId, 'cleared', 'open', 'cleared', null, $note, $at);
    }

    public static function event(int $flagId, string $kind, ?string $from, ?string $to, ?string $actor, ?string $note, string $at): void
    {
        DB::table('quality_flag_events')->insert([
            'flag_id' => $flagId,
            'kind' => $kind,
            'from_value' => $from,
            'to_value' => $to,
            'actor' => $actor,
            'note' => $note,
            'at' => $at,
        ]);
    }

    private static function json(array $details): ?string
    {
        return $details === [] ? null : json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
