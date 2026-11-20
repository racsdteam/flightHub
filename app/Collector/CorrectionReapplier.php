<?php

namespace App\Collector;

use App\Aodb\CanonicalJson;
use App\Api\ApiResponses;
use App\Quality\QualityEvaluator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Re-evaluates the served values of every flight against the active
 * airport_code_corrections and aircraft_types (after reference data changed).
 * A changed served value → new version (cause correction) + correction events,
 * under GET_LOCK('flighthub_change_events'). Idempotent.
 *
 * Then the quality rules run as after an ok poll (same rules, same set: the
 * in-feed flights of the poll window), in their own transaction, so flags
 * follow corrected values and changed stands without waiting for a poll.
 * Only while the feed is ok: a poll evaluates only after an ok poll, and the
 * clock rules would flag times the hub has not received yet (no actual after
 * schedule…) on stale data; the next ok poll evaluates instead.
 */
final class CorrectionReapplier
{
    public function __construct(
        private readonly int $lockTimeout = 10,
        private readonly QualityEvaluator $quality = new QualityEvaluator,
    ) {}

    /**
     * quality is null, with the reason in quality_skipped, when the feed is stale or
     * AODB_WINDOW_DAYS_* give an invalid window (no poll evaluates then either).
     *
     * @return array{flights: int, events: int, quality: array{evaluated: int, detected: int, cleared: int, reopened: int}|null, quality_skipped: string|null}
     */
    public function run(): array
    {
        $locked = (int) DB::selectOne('SELECT GET_LOCK(?, ?) AS l', [Poller::LOCK, $this->lockTimeout])->l === 1;
        if (! $locked) {
            throw new RuntimeException('The change events lock is busy (a poll is writing); try again.');
        }

        try {
            $now = CarbonImmutable::now('UTC');
            $result = DB::transaction(fn () => $this->apply(FlightNormaliser::fromDatabase(), $now));

            if (ApiResponses::feed()['feed'] !== 'ok') {
                return $result + ['quality' => null, 'quality_skipped' => 'the feed is stale; the next ok poll runs them'];
            }
            try {
                $window = Poller::window($now);
            } catch (InvalidArgumentException) {
                return $result + ['quality' => null, 'quality_skipped' => 'AODB_WINDOW_DAYS_* give an invalid window'];
            }

            return $result + ['quality' => $this->quality->evaluate($window, $now), 'quality_skipped' => null];
        } finally {
            DB::selectOne('SELECT RELEASE_LOCK(?) AS l', [Poller::LOCK]);
        }
    }

    private function apply(FlightNormaliser $normaliser, CarbonImmutable $now): array
    {
        $at = $now->format('Y-m-d H:i:s.u');
        $counts = ['flights' => 0, 'events' => 0];

        DB::table('flights')->orderBy('id')->chunkById(500, function ($flights) use ($normaliser, $at, &$counts) {
            foreach ($flights as $flight) {
                $served = $normaliser->normalise(CanonicalJson::decode($flight->record));

                $changed = [];
                foreach (FlightNormaliser::SERVED as $column => [, $path]) {
                    $before = $flight->{$column};
                    $after = $served->columns[$column];
                    if (($before === null) !== ($after === null) || (string) $before !== (string) $after) {
                        $changed[$column] = $path;
                    }
                }
                if ($changed === []) {
                    continue;
                }

                $columns = [];
                foreach ([...FlightNormaliser::versionColumns(), ...FlightNormaliser::HUB_COLUMNS] as $column) {
                    $columns[$column] = $flight->{$column};
                }
                foreach (array_keys($changed) as $column) {
                    $columns[$column] = $served->columns[$column];
                }

                $versionNo = $flight->current_version_no + 1;
                $versionId = DB::table('flight_versions')->insertGetId($columns + [
                    'flight_id' => $flight->id,
                    'version_no' => $versionNo,
                    'valid_from' => $at,
                    'poll_id' => null,
                    'cause' => 'correction',
                    'in_feed' => $flight->in_feed,
                ]);

                DB::table('flights')->where('id', $flight->id)->update(array_intersect_key($columns, $changed) + [
                    'current_version_no' => $versionNo,
                    'current_version_id' => $versionId,
                    'updated_at' => substr($at, 0, 19),
                ]);

                $json = fn ($value) => $value === null ? null : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $events = [];
                foreach ($changed as $column => $path) {
                    $events[] = [
                        'flight_id' => $flight->id,
                        'version_id' => $versionId,
                        'kind' => 'field_changed',
                        'field' => $path,
                        'old_value' => $json($flight->{$column}),
                        'new_value' => $json($served->columns[$column]),
                        'source' => 'correction',
                        // null when no correction applies any more (back to the AODB value).
                        'source_ref' => $served->corrections[$path] ?? null,
                        'poll_id' => null,
                        'observed_at' => $at,
                    ];
                }
                DB::table('change_events')->insert($events);

                $counts['flights']++;
                $counts['events'] += count($events);
            }
        });

        return $counts;
    }
}
