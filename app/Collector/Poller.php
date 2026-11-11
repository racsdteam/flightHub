<?php

namespace App\Collector;

use App\Aodb\AodbClient;
use App\Aodb\AodbConfigurationException;
use App\Aodb\AodbResponse;
use App\Aodb\AodbSpec;
use App\Aodb\CanonicalJson;
use App\Flights\Window;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;
use Throwable;

/**
 * One AODB poll (hub:poll), on the runtime connection.
 *
 * Every attempt ends with exactly one polls row. Unchanged content touches
 * last_seen_at only. Changed content is written in ONE transaction holding
 * GET_LOCK('flighthub_change_events'), so change_events ids commit in order.
 */
final class Poller
{
    public const LOCK = 'flighthub_change_events';

    public const AIRPORT_ENDPOINT = '/flights/v1/airport';

    private const DEP_TIMES = ['sobt', 'eobt', 'tobt', 'pobt', 'aobt', 'etot', 'ttot', 'ctot', 'atot'];

    private const ARR_TIMES = ['sibt', 'eibt', 'eldt', 'aibt', 'aldt'];

    private const REMOVAL_MARGIN_SECONDS = 3600;

    private const MAX_WARNINGS = 200;

    private CarbonImmutable $startedAt;

    private CarbonImmutable $observedAt;

    private Window $window;

    /** @var array<string, mixed> the polls row being built */
    private array $poll = [];

    /** @var list<array{code: string, aodb_id?: string, detail?: string}> */
    private array $warnings = [];

    private int $droppedWarnings = 0;

    /**
     * @param  Closure(): AodbClient  $client  resolved lazily: a misconfigured client is a config_error poll
     */
    public function __construct(
        private readonly Closure $client,
        private readonly int $lockTimeout = 10,
    ) {}

    /**
     * @return array<string, mixed> the polls row written
     */
    public function run(): array
    {
        $this->startedAt = CarbonImmutable::now('UTC');
        $this->poll = [
            'outcome' => AodbResponse::OK,
            'http_status' => null,
            'error_summary' => null,
            'payload_sha256' => null,
            'content_changed' => false,
            'raw_response_id' => null,
            'flights_in_payload' => null,
            'flights_new' => 0,
            'flights_changed' => 0,
            'flights_removed' => 0,
            'flights_returned' => 0,
            'events_written' => 0,
        ];

        try {
            $this->window = $this->window($this->startedAt);
        } catch (InvalidArgumentException) {
            $this->window = Window::kigaliDay($this->startedAt->setTimezone('Africa/Kigali')->format('Y-m-d'));

            return $this->finish(AodbResponse::CONFIG_ERROR, 'AODB_WINDOW_DAYS_* give a window longer than 7 days');
        }

        try {
            return $this->poll();
        } catch (AodbConfigurationException $e) {
            return $this->finish(AodbResponse::CONFIG_ERROR, $e->getMessage());
        } catch (Throwable $e) {
            // Leave a trace in polls, then let the scheduler see the failure.
            // Only the class name: messages can carry SQL or payload data.
            $this->finish('internal_error', class_basename($e));
            throw $e;
        }
    }

    /**
     * Kigali yesterday 00:00 → tomorrow 24:00 by default (D-025), in UTC.
     */
    public function window(CarbonImmutable $now): Window
    {
        $today = $now->setTimezone('Africa/Kigali')->startOfDay();

        return Window::between(
            $today->subDays((int) config('aodb.window_days_before')),
            $today->addDays((int) config('aodb.window_days_after') + 1),
        );
    }

    private function poll(): array
    {
        $client = ($this->client)();
        $response = $client->flightsAtAirport($this->window);
        $this->observedAt = CarbonImmutable::now('UTC');
        $this->poll['http_status'] = $response->httpStatus;

        // Not 2xx: the outcome only; the body is never stored.
        if (! $response->hasBody()) {
            return $this->finish($response->outcome, $response->error);
        }

        $sha = $response->payload !== null ? CanonicalJson::sha256($response->payload) : hash('sha256', (string) $response->body);
        $this->poll['payload_sha256'] = $sha;
        $last = DB::table('raw_responses')->where('endpoint', self::AIRPORT_ENDPOINT)->orderByDesc('id')->first(['id', 'sha256']);
        $changed = $last === null || $last->sha256 !== $sha;

        if (! $response->isOk()) {
            $this->poll['content_changed'] = $changed;
            $this->poll['raw_response_id'] = $changed ? $this->storeRaw($response, $sha, withWindow: true) : $last->id;

            return $this->finish(AodbResponse::INVALID_PAYLOAD, $response->error);
        }

        $this->poll['flights_in_payload'] = count($response->flights);
        $records = $this->validRecords($response->flights);

        if (! $changed) {
            $this->poll['raw_response_id'] = $last->id;
            $this->touchLastSeen(array_keys($records));

            return $this->finish(AodbResponse::OK);
        }

        $this->poll['content_changed'] = true;
        $this->warnUnknownFields($records);
        [$missing, $byId, $byIdAnswers] = $this->askAboutMissing($client, $records);

        $locked = (int) DB::selectOne('SELECT GET_LOCK(?, ?) AS l', [self::LOCK, $this->lockTimeout])->l === 1;
        if (! $locked) {
            // Nothing written, raw included: the next poll sees the change again.
            return $this->finish('lock_timeout', 'GET_LOCK '.self::LOCK.' timed out');
        }

        try {
            return DB::transaction(fn () => $this->writeChanges($response, $sha, $records, $missing, $byId, $byIdAnswers));
        } finally {
            DB::selectOne('SELECT RELEASE_LOCK(?) AS l', [self::LOCK]);
        }
    }

    /**
     * Records with a usable id, first one kept per id.
     *
     * @param  list<mixed>  $flights
     * @return array<string, stdClass>
     */
    private function validRecords(array $flights): array
    {
        $records = [];
        foreach ($flights as $i => $record) {
            $id = $record instanceof stdClass ? ($record->id ?? null) : null;
            if (! is_string($id) || $id === '' || mb_strlen($id) > 64) {
                $this->warn('missing_id', null, "flights[{$i}]");

                continue;
            }
            if (isset($records[$id])) {
                $this->warn('duplicate_id', $id, "flights[{$i}] skipped; first kept");

                continue;
            }
            $records[$id] = $record;
        }

        return $records;
    }

    /**
     * @param  array<string, stdClass>  $records
     */
    private function warnUnknownFields(array $records): void
    {
        $seen = [];
        foreach ($records as $id => $record) {
            foreach (array_keys(CanonicalJson::leaves($record)) as $path) {
                if (! isset($seen[$path]) && ! AodbSpec::isDocumented($path)) {
                    $seen[$path] = true;
                    $this->warn('unknown_field', $id, $path);
                }
            }
        }
    }

    /**
     * In-feed flights missing from the payload whose last known OBT/TOT or
     * LDT/IBT lies at least 60 min inside the window are asked for by id.
     * Read-only, before the lock: no HTTP call while the lock is held.
     * Returns the missing ids, the byid records (null when byid failed) and
     * every byid answer (stored later as evidence).
     *
     * @param  array<string, stdClass>  $records
     * @return array{0: list<string>, 1: array<string, stdClass>|null, 2: list<array{response: AodbResponse, ids: list<string>}>}
     */
    private function askAboutMissing(AodbClient $client, array $records): array
    {
        $candidates = DB::table('flights')
            ->forceIndex('flights_in_feed_reference_time_index')
            ->where('in_feed', true)
            ->whereBetween('reference_time', [
                $this->window->from->modify('-7 days')->format('Y-m-d H:i:s'),
                $this->window->to->modify('+7 days')->format('Y-m-d H:i:s'),
            ])
            ->get(['aodb_id', 'record']);

        $missing = [];
        foreach ($candidates as $flight) {
            if (! isset($records[$flight->aodb_id]) && $this->insideMargin(CanonicalJson::decode($flight->record))) {
                $missing[] = $flight->aodb_id;
            }
        }

        if ($missing === []) {
            return [[], [], []];
        }

        $found = [];
        $answers = [];
        foreach (array_chunk($missing, max(1, (int) config('aodb.byid_chunk'))) as $chunk) {
            $response = $client->flightsById($chunk);
            $answers[] = ['response' => $response, 'ids' => $chunk];
            if (! $response->isOk()) {
                $this->warn('byid_failed', null, (string) $response->error);

                return [$missing, null, $answers];
            }
            foreach ($response->flights as $record) {
                $id = $record instanceof stdClass ? ($record->id ?? null) : null;
                if (is_string($id) && in_array($id, $chunk, true) && ! isset($found[$id])) {
                    $found[$id] = $record;
                }
            }
        }

        return [$missing, $found, $answers];
    }

    private function insideMargin(stdClass $record): bool
    {
        $flow = $record->flow ?? null;
        $fields = match ($flow) {
            'DEP' => self::DEP_TIMES,
            'ARR' => self::ARR_TIMES,
            default => [...self::DEP_TIMES, ...self::ARR_TIMES],
        };
        $from = $this->window->from->getTimestamp() + self::REMOVAL_MARGIN_SECONDS;
        $to = $this->window->to->getTimestamp() - self::REMOVAL_MARGIN_SECONDS;

        foreach ($fields as $field) {
            $time = FlightNormaliser::parseTime($record->{$field} ?? null)?->getTimestamp();
            if ($time !== null && $time >= $from && $time <= $to) {
                return true;
            }
        }

        return false;
    }

    /**
     * Inside the transaction and the lock: plan every change from the stored
     * state, insert the polls row, then flights, versions and events.
     *
     * @param  array<string, stdClass>  $records
     * @param  list<string>  $missing
     * @param  array<string, stdClass>|null  $byId
     * @param  list<array{response: AodbResponse, ids: list<string>}>  $byIdAnswers
     */
    private function writeChanges(AodbResponse $response, string $sha, array $records, array $missing, ?array $byId, array $byIdAnswers): array
    {
        $this->poll['raw_response_id'] = $this->storeRaw($response, $sha, withWindow: true);

        // byid evidence (D-008 per endpoint): which stored answer covered each asked id.
        $evidence = [];
        foreach ($byIdAnswers as ['response' => $answer, 'ids' => $ids]) {
            if ($answer->hasBody()) {
                $rawId = $this->storeRawUnlessSame($answer);
                foreach ($ids as $id) {
                    $evidence[$id] = $rawId;
                }
            }
        }

        // byid answers are processed like any record (the flight moved; still in feed).
        $seen = $records;
        foreach ($byId ?? [] as $id => $record) {
            $seen[$id] = $record;
        }

        $corrections = DB::table('airport_code_corrections')->where('active', true)->get()->keyBy('aodb_value')->all();
        $normaliser = new FlightNormaliser($corrections);
        $stored = DB::table('flights')->whereIn('aodb_id', [...array_keys($seen), ...$missing])->get()->keyBy('aodb_id');

        $plans = [];
        foreach ($seen as $id => $record) {
            $flight = $normaliser->normalise($record);
            $current = $stored[$id] ?? null;
            $plan = $current === null ? $this->planNew($id, $flight) : $this->planSeen($current, $flight);
            if ($plan !== null) {
                $plans[] = $plan;
            }
        }

        // Missing and not returned by byid: removed. byid failed: conclude nothing.
        if ($byId !== null) {
            foreach ($missing as $id) {
                $current = $stored[$id] ?? null;
                if ($current !== null && ! isset($byId[$id]) && $current->in_feed) {
                    $plans[] = $this->planRemoved($current, $evidence[$id] ?? null);
                }
            }
        }

        $this->poll['events_written'] = array_sum(array_map(fn ($plan) => count($plan['events']), $plans));
        $pollId = $this->insertPoll(AodbResponse::OK);

        foreach ($plans as $plan) {
            $this->write($plan, $pollId);
        }
        $this->touchLastSeen(array_keys($seen));

        return $this->poll + ['id' => $pollId];
    }

    private function planNew(string $id, NormalisedFlight $flight): array
    {
        $this->poll['flights_new']++;

        $events = [$this->event('first_seen', null, null, CanonicalJson::encode((object) [
            'record' => CanonicalJson::decode($flight->columns['record']),
            'served' => (object) $flight->served(),
        ]), 'aodb')];

        foreach ($flight->corrections as $path => $ref) {
            [$aodbValue, $servedValue] = $this->servedPair($flight, $path);
            $events[] = $this->event('field_changed', $path, self::json($aodbValue), self::json($servedValue), 'correction', $ref);
        }

        return ['aodb_id' => $id, 'current' => null, 'columns' => $flight->columns, 'in_feed' => true, 'cause' => 'aodb', 'events' => $events];
    }

    /**
     * An existing flight present in the feed (or returned by byid).
     */
    private function planSeen(object $current, NormalisedFlight $flight): ?array
    {
        $events = [];
        $old = CanonicalJson::leaves(CanonicalJson::decode($current->record));
        $new = CanonicalJson::leaves(CanonicalJson::decode($flight->columns['record']));
        $paths = array_unique([...array_keys($old), ...array_keys($new)]);
        sort($paths, SORT_STRING);

        foreach ($paths as $path) {
            // Absent equals null (D-020).
            $before = $old[$path] ?? 'null';
            $after = $new[$path] ?? 'null';
            if ($before !== $after) {
                $events[] = $this->event('field_changed', $path, $before === 'null' ? null : $before, $after === 'null' ? null : $after, 'aodb');
            }
        }
        $recordChanged = $events !== [];

        if ($recordChanged) {
            foreach (FlightNormaliser::SERVED as $column => [, $path]) {
                $before = $current->{$column};
                $after = $flight->columns[$column];
                if (isset($flight->corrections[$path]) && (string) $before !== (string) $after) {
                    $events[] = $this->event('field_changed', $path, self::json($before), self::json($after), 'correction', $flight->corrections[$path]);
                }
            }
            $this->poll['flights_changed']++;
        }

        // Returning to the feed is the hub's own conclusion: source system.
        if (! $current->in_feed) {
            $events[] = $this->event('returned_to_feed', null, null, null, 'system');
            $this->poll['flights_returned']++;
        }

        if ($events === []) {
            return null;
        }

        // A version that carries AODB field changes is caused by the AODB; a bare return by the hub.
        return [
            'aodb_id' => $current->aodb_id,
            'current' => $current,
            'columns' => $flight->columns,
            'in_feed' => true,
            'cause' => $recordChanged ? 'aodb' : 'system',
            'events' => $events,
        ];
    }

    /**
     * Removal is the hub's own conclusion: source and cause system; source_ref
     * the stored byid answer that did not contain the flight.
     */
    private function planRemoved(object $current, ?int $evidenceRawId): array
    {
        $this->poll['flights_removed']++;
        $columns = [];
        foreach (FlightNormaliser::versionColumns() as $column) {
            $columns[$column] = $current->{$column};
        }

        return [
            'aodb_id' => $current->aodb_id,
            'current' => $current,
            'columns' => $columns,
            'in_feed' => false,
            'cause' => 'system',
            'events' => [$this->event('removed_from_feed', null, null, null, 'system', $evidenceRawId === null ? null : 'raw_responses:'.$evidenceRawId)],
        ];
    }

    private function write(array $plan, int $pollId): void
    {
        $at = $this->observedAt->format('Y-m-d H:i:s.u');
        $now = $this->observedAt->format('Y-m-d H:i:s');
        $current = $plan['current'];
        $versionNo = $current === null ? 1 : $current->current_version_no + 1;

        if ($current === null) {
            $flightId = DB::table('flights')->insertGetId($plan['columns'] + [
                'aodb_id' => $plan['aodb_id'],
                'in_feed' => true,
                'first_seen_at' => $at,
                'last_seen_at' => $at,
                'current_version_no' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $flightId = $current->id;
        }

        $versionId = DB::table('flight_versions')->insertGetId($plan['columns'] + [
            'flight_id' => $flightId,
            'version_no' => $versionNo,
            'valid_from' => $at,
            'poll_id' => $pollId,
            'cause' => $plan['cause'],
            'in_feed' => $plan['in_feed'],
        ]);

        DB::table('flights')->where('id', $flightId)->update($plan['columns'] + [
            'in_feed' => $plan['in_feed'],
            'removed_from_feed_at' => $plan['in_feed'] ? null : $at,
            'last_seen_at' => $plan['in_feed'] ? $at : $current->last_seen_at,
            'current_version_no' => $versionNo,
            'current_version_id' => $versionId,
            'updated_at' => $now,
        ]);

        DB::table('change_events')->insert(array_map(fn (array $event) => $event + [
            'flight_id' => $flightId,
            'version_id' => $versionId,
            'poll_id' => $pollId,
            'observed_at' => $at,
        ], $plan['events']));
    }

    private function event(string $kind, ?string $field, ?string $old, ?string $new, string $source, ?string $ref = null): array
    {
        return [
            'kind' => $kind,
            'field' => $field,
            'old_value' => $old,
            'new_value' => $new,
            'source' => $source,
            'source_ref' => $ref,
        ];
    }

    /**
     * [value as received, served value] for the served column at an AODB path.
     */
    private function servedPair(NormalisedFlight $flight, string $path): array
    {
        foreach (FlightNormaliser::SERVED as $column => [$twin, $servedPath]) {
            if ($servedPath === $path) {
                return [$flight->columns[$twin], $flight->columns[$column]];
            }
        }

        return [null, null];
    }

    private static function json(mixed $value): ?string
    {
        return $value === null ? null : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * A byid answer is stored unless it equals the last stored raw of the same
     * endpoint (D-008); then that row is reused.
     */
    private function storeRawUnlessSame(AodbResponse $response): int
    {
        $sha = $response->payload !== null ? CanonicalJson::sha256($response->payload) : hash('sha256', (string) $response->body);
        $last = DB::table('raw_responses')->where('endpoint', $response->endpoint)->orderByDesc('id')->first(['id', 'sha256']);

        return $last !== null && $last->sha256 === $sha ? (int) $last->id : $this->storeRaw($response, $sha, withWindow: false);
    }

    private function storeRaw(AodbResponse $response, string $sha, bool $withWindow): int
    {
        $gz = gzencode((string) $response->body, 6);

        return DB::table('raw_responses')->insertGetId([
            'received_at' => $this->observedAt->format('Y-m-d H:i:s.u'),
            'sha256' => $sha,
            'body' => $gz,
            'bytes_raw' => strlen((string) $response->body),
            'bytes_compressed' => strlen($gz),
            'http_status' => $response->httpStatus,
            'endpoint' => $response->endpoint,
            'request_window_from' => $withWindow ? $this->window->from->format('Y-m-d H:i:s') : null,
            'request_window_to' => $withWindow ? $this->window->to->format('Y-m-d H:i:s') : null,
        ]);
    }

    /**
     * @param  list<string>  $ids
     */
    private function touchLastSeen(array $ids): void
    {
        if ($ids !== []) {
            DB::table('flights')->whereIn('aodb_id', $ids)->update(['last_seen_at' => $this->observedAt->format('Y-m-d H:i:s.u')]);
        }
    }

    private function warn(string $code, ?string $aodbId, ?string $detail): void
    {
        if (count($this->warnings) >= self::MAX_WARNINGS) {
            $this->droppedWarnings++;

            return;
        }
        $this->warnings[] = array_filter(['code' => $code, 'aodb_id' => $aodbId, 'detail' => $detail], fn ($v) => $v !== null);
    }

    private function finish(string $outcome, ?string $error = null): array
    {
        $this->poll['error_summary'] = $error;
        $id = $this->insertPoll($outcome);

        return $this->poll + ['id' => $id];
    }

    private function insertPoll(string $outcome): int
    {
        $finished = CarbonImmutable::now('UTC');
        $warnings = $this->warnings;
        if ($this->droppedWarnings > 0) {
            $warnings[] = ['code' => 'warnings_truncated', 'detail' => $this->droppedWarnings.' more'];
        }

        $this->poll = [
            'started_at' => $this->startedAt->format('Y-m-d H:i:s.u'),
            'finished_at' => $finished->format('Y-m-d H:i:s.u'),
            'duration_ms' => (int) round(($finished->getPreciseTimestamp(3) - $this->startedAt->getPreciseTimestamp(3))),
            'request_window_from' => $this->window->from->format('Y-m-d H:i:s'),
            'request_window_to' => $this->window->to->format('Y-m-d H:i:s'),
            'outcome' => $outcome,
            'warnings' => $warnings === [] ? null : json_encode($warnings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ] + $this->poll;
        $this->poll['error_summary'] = $this->poll['error_summary'] === null ? null : mb_substr($this->poll['error_summary'], 0, 500);

        return DB::table('polls')->insertGetId($this->poll);
    }
}
