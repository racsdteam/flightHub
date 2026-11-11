<?php

namespace App\Collector;

use App\Aodb\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Re-evaluates the served values of every flight against the active
 * airport_code_corrections and aircraft_types (after reference data changed).
 * A changed served value → new version (cause correction) + correction events,
 * under GET_LOCK('flighthub_change_events'). Idempotent.
 */
final class CorrectionReapplier
{
    public function __construct(private readonly int $lockTimeout = 10) {}

    /**
     * @return array{flights: int, events: int}
     */
    public function run(): array
    {
        $locked = (int) DB::selectOne('SELECT GET_LOCK(?, ?) AS l', [Poller::LOCK, $this->lockTimeout])->l === 1;
        if (! $locked) {
            throw new RuntimeException('The change events lock is busy (a poll is writing); try again.');
        }

        try {
            return DB::transaction(fn () => $this->apply(FlightNormaliser::fromDatabase(), CarbonImmutable::now('UTC')));
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
