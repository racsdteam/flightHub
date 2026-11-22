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
 *
 * A poll and a re-apply never run the rules at the same time: both go through
 * evaluateLocked(), under GET_LOCK('flighthub_quality'). Flights known only
 * from the backfill (current version cause backfill, D-046) are not evaluated.
 */
final class QualityEvaluator
{
    public const LOCK = 'flighthub_quality';

    /**
     * The rules under the quality lock; null when the lock stayed busy for the timeout
     * (config quality.lock_timeout_seconds unless given).
     *
     * @return array{evaluated: int, detected: int, cleared: int, reopened: int}|null
     */
    public function evaluateLocked(Window $window, CarbonImmutable $now, ?int $timeout = null): ?array
    {
        $timeout ??= (int) config('quality.lock_timeout_seconds', 10);
        if ((int) DB::selectOne('SELECT GET_LOCK(?, ?) AS l', [self::LOCK, $timeout])->l !== 1) {
            return null;
        }

        try {
            return $this->evaluate($window, $now);
        } finally {
            DB::selectOne('SELECT RELEASE_LOCK(?) AS l', [self::LOCK]);
        }
    }

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
        $stands = DB::table('stands')->where('active', true)->pluck('code')->all();
        $rules = new FlightRules(config('quality'), $stands);

        // The window set, plus in-feed flights with no time at all seen recently (no window position).
        $flights = DB::table('flights')
            ->where('in_feed', true)
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q
                    ->where('reference_time', '>=', $window->from->format('Y-m-d H:i:s'))
                    ->where('reference_time', '<', $window->to->format('Y-m-d H:i:s')))
                ->orWhere(fn ($q) => $q
                    ->whereNull('reference_time')
                    ->where('last_seen_at', '>=', $now->subHours(FlightRules::NO_TIMES_SEEN_HOURS)->format('Y-m-d H:i:s.u'))))
            // Known only from the backfill (D-046): never evaluated.
            ->whereNotExists(fn ($q) => $q->from('flight_versions as v')
                ->whereColumn('v.id', 'flights.current_version_id')
                ->where('v.cause', 'backfill'))
            ->orderBy('id')
            ->get();
        $counts['evaluated'] = $flights->count();

        $duplicates = $rules->duplicates($flights);
        $existing = DB::table('quality_flags')
            ->whereIn('flight_id', $flights->pluck('id')->all())
            ->get()
            ->map(fn ($flag) => $this->rekey($flag))
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

    /**
     * Keys are recomputed at evaluation (rule|field|related flights.id), so a
     * flag stored under an older key format keeps its history and resolution.
     */
    private function rekey(object $flag): object
    {
        $key = Condition::keyOf($flag->rule, $flag->field, $flag->related_flight_id);
        if ($key !== $flag->flag_key) {
            DB::table('quality_flags')->where('id', $flag->id)->update(['flag_key' => $key]);
            $flag->flag_key = $key;
        }

        return $flag;
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
