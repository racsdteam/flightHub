<?php

namespace App\Http\Controllers\Api;

use App\Api\ApiException;
use App\Api\ApiResponses;
use App\Api\FieldSet;
use App\Api\FlightPresenter;
use App\Collector\FlightNormaliser;
use App\Flights\FlightQuery;
use App\Flights\Window;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Distribution API v1 (read-only). The consumer and its field groups come
 * from ApiGate.
 */
class ApiController
{
    private const CHANGES_DEFAULT_LIMIT = 500;

    private const CHANGES_MAX_LIMIT = 1000;

    /**
     * GET /flights?from=…&to=… | ?day=YYYY-MM-DD [&as_of=…][&flow=ARR|DEP][&include_removed=1]
     * Sorted by reference time. Same-as flights are left out (D-038); removed
     * flights unless include_removed=1. as_of: the same rules as of that moment.
     */
    public function flights(Request $request): JsonResponse
    {
        $window = $this->window($request);
        $asOf = $this->time($request, 'as_of');
        $flow = $request->query('flow');
        if ($flow !== null && ! in_array($flow, ['ARR', 'DEP'], true)) {
            throw ApiException::invalid('flow must be ARR or DEP.');
        }
        $includeRemoved = $this->flag($request, 'include_removed');

        $rows = (new FlightQuery)->flightsInWindow($window, $asOf)
            ->filter(fn ($row) => $row->same_as_flight_id === null)
            ->filter(fn ($row) => $includeRemoved || $row->in_feed)
            ->filter(fn ($row) => $flow === null || $row->flow === $flow)
            ->values();

        $presenter = FlightPresenter::for($this->fields($request), $rows, $asOf);
        $request->attributes->set('api.rows', $rows->count());

        return ApiResponses::data($rows->map(fn ($row) => $presenter->flight($row))->all(), ApiResponses::feed() + [
            'window' => ['from' => $window->from->format('Y-m-d\TH:i:s\Z'), 'to' => $window->to->format('Y-m-d\TH:i:s\Z')],
            'requestedAsOf' => $asOf?->format('Y-m-d\TH:i:s\Z'),
        ]);
    }

    /**
     * GET /flights/{aodbId}[?history=1]: the flight, even when hidden from lists.
     */
    public function flight(Request $request, string $aodbId): JsonResponse
    {
        $row = DB::table('flights')->where('aodb_id', $aodbId)->first() ?? throw ApiException::notFound('Flight not found.');
        $fields = $this->fields($request);
        $presenter = FlightPresenter::for($fields, collect([$row]));
        $extra = [];

        if ($this->flag($request, 'history')) {
            $events = DB::table('change_events')->where('flight_id', $row->id)->orderBy('id')
                ->get()->each(fn ($e) => $e->aodb_id = $row->aodb_id);
            $extra['history'] = FlightPresenter::events($fields, $events);
        }

        $request->attributes->set('api.rows', 1 + count($extra['history'] ?? []));

        return ApiResponses::data($presenter->flight($row), ApiResponses::feed(), $extra);
    }

    /**
     * GET /changes?cursor=N[&limit=500]: events with id > N in id order,
     * filtered by field groups. nextCursor = the last event id SCANNED, so
     * events the consumer may not see never block it.
     */
    public function changes(Request $request): JsonResponse
    {
        $cursor = $request->query('cursor');
        if (! is_string($cursor) || ! preg_match('/^\d{1,19}$/', $cursor)) {
            throw ApiException::invalid('cursor is required: 0 or a previous nextCursor.');
        }
        $limit = $request->query('limit', (string) self::CHANGES_DEFAULT_LIMIT);
        if (! is_string($limit) || ! preg_match('/^\d{1,9}$/', $limit) || (int) $limit < 1) {
            throw ApiException::invalid('limit must be a positive integer.');
        }
        $limit = min((int) $limit, self::CHANGES_MAX_LIMIT);

        $scanned = DB::table('change_events as e')
            ->join('flights as f', 'f.id', '=', 'e.flight_id')
            ->where('e.id', '>', (int) $cursor)
            ->orderBy('e.id')
            ->limit($limit)
            ->get(['e.*', 'f.aodb_id']);

        $events = FlightPresenter::events($this->fields($request), $scanned);
        $next = $scanned->isEmpty() ? (int) $cursor : (int) $scanned->last()->id;
        $head = (int) (DB::table('change_events')->max('id') ?? 0);
        $request->attributes->set('api.rows', count($events));

        return ApiResponses::data($events, ApiResponses::feed() + [
            'cursor' => (int) $cursor,
            'limit' => $limit,
            'nextCursor' => $next,
            'hasMore' => $head > $next,
            'headCursor' => $head,
        ]);
    }

    /**
     * GET /health: freshness of the feed and the last poll's outcome.
     */
    public function health(Request $request): JsonResponse
    {
        $last = DB::table('polls')->orderByDesc('id')->first(['outcome', 'finished_at']);
        $feed = ApiResponses::feed();
        $request->attributes->set('api.rows', 1);

        return ApiResponses::data($feed + [
            'lastPoll' => $last === null ? null : ['outcome' => $last->outcome, 'finishedAt' => FlightPresenter::iso($last->finished_at)],
        ], $feed);
    }

    public function notFound(): never
    {
        throw ApiException::notFound();
    }

    /**
     * Any other method on any /api/v1 path: through ApiGate (so it is logged), then 405.
     */
    public function methodNotAllowed(): never
    {
        throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Method not allowed.', ['Allow' => 'GET, HEAD']);
    }

    private function fields(Request $request): FieldSet
    {
        $groups = json_decode((string) $request->attributes->get('api.consumer')->field_groups, true);

        return FieldSet::forGroups(is_array($groups) ? $groups : []);
    }

    /**
     * [from, to) from from+to (ISO-8601 with a zone) or day (Kigali calendar day); at most 7 days.
     */
    private function window(Request $request): Window
    {
        $day = $request->query('day');
        $hasRange = $request->query('from') !== null || $request->query('to') !== null;

        try {
            if ($day !== null && ! $hasRange) {
                if (! is_string($day)) {
                    throw ApiException::invalid('day must be YYYY-MM-DD.');
                }

                return Window::kigaliDay($day);
            }
            if ($day === null && $hasRange) {
                return Window::between($this->time($request, 'from', required: true), $this->time($request, 'to', required: true));
            }
        } catch (InvalidArgumentException $e) {
            throw ApiException::invalid($e->getMessage());
        }

        throw ApiException::invalid('Give either day=YYYY-MM-DD or from and to.');
    }

    private function time(Request $request, string $name, bool $required = false): ?CarbonImmutable
    {
        $value = $request->query($name);
        if ($value === null && ! $required) {
            return null;
        }
        $time = FlightNormaliser::parseTime($value);
        if ($time === null) {
            throw ApiException::invalid("{$name} must be an ISO-8601 date-time with a zone, e.g. 2026-10-29T05:00:00Z.");
        }

        return CarbonImmutable::instance($time);
    }

    private function flag(Request $request, string $name): bool
    {
        return match ($request->query($name)) {
            null, '0', 'false' => false,
            '1', 'true' => true,
            default => throw ApiException::invalid("{$name} must be 1 or 0."),
        };
    }
}
