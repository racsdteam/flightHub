<?php

namespace App\Collector;

use App\Aodb\AodbClient;
use App\Aodb\AodbResponse;
use App\Aodb\CanonicalJson;
use App\Flights\Window;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use stdClass;

/**
 * One-time backfill at go-live (D-046): the final state of past flights, never change history.
 *
 * One flightsAtAirport call per Kigali day, oldest first, from today − days up to the day before
 * the poll window starts, 2 s apart. A refusal (any outcome but ok: 4xx, rate_limited,
 * login_backoff…) or a day with no flights stops the run; nothing more is written. Days already
 * backfilled (a stored answer for exactly that day's window) are skipped, so a re-run resumes.
 *
 * Unknown flights only: flight row + version 1 (cause backfill) + ONE first_seen event (source
 * backfill, source_ref the stored answer). Known flights are never touched; no field_changed or
 * correction events; no rotations; no quality evaluation (QualityEvaluator skips flights whose
 * current version is cause backfill). Each day is written in one transaction under
 * GET_LOCK('flighthub_change_events'). Raw answers follow D-008 (compressed; an identical answer
 * for the same window is reused).
 */
final class Backfiller
{
    public const SOURCE = 'backfill';

    private const SECONDS_BETWEEN_CALLS = 2;

    /**
     * @param  Closure(string): void|null  $report  one line per day
     */
    public function __construct(
        private readonly AodbClient $client,
        private readonly int $lockTimeout = 10,
        private readonly ?Closure $report = null,
    ) {}

    /**
     * The Kigali days to backfill: today − days … the day before the poll window starts.
     *
     * @return list<string> Y-m-d, oldest first
     */
    public static function days(int $days, CarbonImmutable $now): array
    {
        $today = $now->setTimezone('Africa/Kigali')->startOfDay();
        $last = $today->subDays((int) config('aodb.window_days_before') + 1);
        $out = [];
        for ($day = $today->subDays($days); $day->lte($last); $day = $day->addDay()) {
            $out[] = $day->format('Y-m-d');
        }

        return $out;
    }

    /**
     * @return array{written: int, skipped: int, new: int, known: int, stopped: string|null}
     */
    public function run(int $days, CarbonImmutable $now): array
    {
        $totals = ['written' => 0, 'skipped' => 0, 'new' => 0, 'known' => 0, 'stopped' => null];
        $called = false;

        foreach (self::days($days, $now) as $date) {
            $window = Window::kigaliDay($date);
            if ($this->alreadyBackfilled($window)) {
                $totals['skipped']++;
                $this->line("{$date}: already backfilled, skipped");

                continue;
            }

            if ($called) {
                Sleep::for(self::SECONDS_BETWEEN_CALLS)->seconds();
            }
            $called = true;
            $response = $this->client->flightsAtAirport($window);

            if (! $response->isOk()) {
                $totals['stopped'] = "{$date}: the AODB answered {$response->outcome}".($response->error !== null ? " ({$response->error})" : '');

                return $totals;
            }
            $records = self::validRecords($response->flights);
            if ($records === []) {
                $totals['stopped'] = "{$date}: the AODB returned no flights for this past day";

                return $totals;
            }

            $locked = (int) DB::selectOne('SELECT GET_LOCK(?, ?) AS l', [Poller::LOCK, $this->lockTimeout])->l === 1;
            if (! $locked) {
                $totals['stopped'] = "{$date}: the change events lock is busy (a poll is writing)";

                return $totals;
            }
            try {
                [$new, $known] = DB::transaction(fn () => $this->write($response, $window, $records));
            } finally {
                DB::selectOne('SELECT RELEASE_LOCK(?) AS l', [Poller::LOCK]);
            }

            $totals['written']++;
            $totals['new'] += $new;
            $totals['known'] += $known;
            $this->line("{$date}: ".count($records)." flights in the answer, {$new} new, {$known} already known");
        }

        return $totals;
    }

    private function alreadyBackfilled(Window $window): bool
    {
        return DB::table('raw_responses')
            ->where('endpoint', Poller::AIRPORT_ENDPOINT)
            ->where('request_window_from', $window->from->format('Y-m-d H:i:s'))
            ->where('request_window_to', $window->to->format('Y-m-d H:i:s'))
            ->exists();
    }

    /**
     * @param  array<string, stdClass>  $records
     * @return array{0: int, 1: int} new, known
     */
    private function write(AodbResponse $response, Window $window, array $records): array
    {
        $observed = CarbonImmutable::now('UTC');
        $at = $observed->format('Y-m-d H:i:s.u');
        $now = $observed->format('Y-m-d H:i:s');
        $rawId = $this->storeRaw($response, $window, $at);

        $known = DB::table('flights')->whereIn('aodb_id', array_keys($records))->pluck('aodb_id')->flip();
        $normaliser = FlightNormaliser::fromDatabase();
        $new = 0;

        foreach ($records as $id => $record) {
            if (isset($known[$id])) {
                continue;
            }
            $flight = $normaliser->normalise($record);
            $hub = array_fill_keys(FlightNormaliser::HUB_COLUMNS, null);

            $flightId = DB::table('flights')->insertGetId($flight->columns + [
                'aodb_id' => $id,
                'in_feed' => true,
                'first_seen_at' => $at,
                'last_seen_at' => $at,
                'current_version_no' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $versionId = DB::table('flight_versions')->insertGetId($flight->columns + $hub + [
                'flight_id' => $flightId,
                'version_no' => 1,
                'valid_from' => $at,
                'poll_id' => null,
                'cause' => self::SOURCE,
                'in_feed' => true,
            ]);
            DB::table('flights')->where('id', $flightId)->update(['current_version_no' => 1, 'current_version_id' => $versionId]);
            DB::table('change_events')->insert([
                'flight_id' => $flightId,
                'version_id' => $versionId,
                'kind' => 'first_seen',
                'field' => null,
                'old_value' => null,
                'new_value' => CanonicalJson::encode((object) [
                    'record' => CanonicalJson::decode($flight->columns['record']),
                    'served' => (object) $flight->served(),
                ]),
                'source' => self::SOURCE,
                'source_ref' => 'raw_responses:'.$rawId,
                'poll_id' => null,
                'observed_at' => $at,
            ]);
            $new++;
        }

        return [$new, count($records) - $new];
    }

    /**
     * Records with a usable id, first one kept per id (as a poll does).
     *
     * @param  list<mixed>  $flights
     * @return array<string, stdClass>
     */
    private static function validRecords(array $flights): array
    {
        $records = [];
        foreach ($flights as $record) {
            $id = $record instanceof stdClass ? ($record->id ?? null) : null;
            if (is_string($id) && $id !== '' && mb_strlen($id) <= 64 && ! isset($records[$id])) {
                $records[$id] = $record;
            }
        }

        return $records;
    }

    /**
     * Compressed (D-008); an identical answer already stored for this window is reused.
     */
    private function storeRaw(AodbResponse $response, Window $window, string $at): int
    {
        $sha = $response->payload !== null ? CanonicalJson::sha256($response->payload) : hash('sha256', (string) $response->body);
        $from = $window->from->format('Y-m-d H:i:s');
        $to = $window->to->format('Y-m-d H:i:s');
        $same = DB::table('raw_responses')->where(['endpoint' => $response->endpoint, 'sha256' => $sha, 'request_window_from' => $from, 'request_window_to' => $to])->value('id');
        if ($same !== null) {
            return (int) $same;
        }
        $gz = gzencode((string) $response->body, 6);

        return DB::table('raw_responses')->insertGetId([
            'received_at' => $at,
            'sha256' => $sha,
            'body' => $gz,
            'bytes_raw' => strlen((string) $response->body),
            'bytes_compressed' => strlen($gz),
            'http_status' => $response->httpStatus,
            'endpoint' => $response->endpoint,
            'request_window_from' => $from,
            'request_window_to' => $to,
        ]);
    }

    private function line(string $line): void
    {
        if ($this->report !== null) {
            ($this->report)($line);
        }
    }
}
