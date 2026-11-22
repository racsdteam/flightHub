<?php

namespace Tests\Feature\Collector;

use App\Aodb\AodbClient;
use App\Aodb\AodbResponse;
use App\Aodb\CanonicalJson;
use App\Collector\Backfiller;
use App\Collector\Poller;
use App\Flights\Window;
use App\Quality\QualityEvaluator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use stdClass;
use Tests\Feature\Api\ApiHelpers;

/**
 * Part E: hub:backfill (D-046) with a fake client. The clock is the sample's (2026-10-29 09:42
 * Kigali), so the poll window starts on 10-28 and --days=3 asks for 10-26 and 10-27.
 */
class BackfillTest extends PollTestCase
{
    use ApiHelpers;

    private object $fake;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();

        $this->fake = new class implements AodbClient
        {
            /** @var array<string, AodbResponse|list<stdClass>> Kigali date → answer */
            public array $answers = [];

            /** @var list<string> Kigali dates asked for */
            public array $asked = [];

            public function flightsAtAirport(Window $window): AodbResponse
            {
                $date = CarbonImmutable::instance($window->from)->setTimezone('Africa/Kigali')->format('Y-m-d');
                $this->asked[] = $date;
                $answer = $this->answers[$date] ?? [];

                return $answer instanceof AodbResponse
                    ? $answer
                    : AodbResponse::fromSuccess('/flights/v1/airport', 200, json_encode(['flights' => $answer], JSON_UNESCAPED_SLASHES));
            }

            public function flightsById(array $ids): AodbResponse
            {
                throw new \LogicException('the backfill never asks by id');
            }
        };
    }

    /**
     * A sample record moved to a past Kigali day under a new id (every top-level time on that day).
     */
    private function pastRecord(string $sampleId, string $newId, string $date): stdClass
    {
        $record = clone self::record($this->sample(), $sampleId);
        $record->id = $newId;
        foreach (get_object_vars($record) as $key => $value) {
            if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T/', $value)) {
                $record->{$key} = $date.substr($value, 10);
            }
        }

        return $record;
    }

    private function known(string $aodbId): bool
    {
        return DB::table('flights')->where('aodb_id', $aodbId)->exists();
    }

    private function backfill(int $days = 3): int
    {
        $this->app->instance(AodbClient::class, $this->fake);

        return $this->artisan('hub:backfill', ['--days' => $days])->run();
    }

    private function answersForTwoDays(): void
    {
        $this->fake->answers = [
            '2026-10-26' => [
                $this->pastRecord(self::KQ470, 'bf-kq470-1026', '2026-10-26'),
                $this->pastRecord(self::WB711, 'bf-wb711-1026', '2026-10-26'),
                self::record($this->sample(), self::KQ478),            // known (polled): never touched
            ],
            '2026-10-27' => [$this->pastRecord(self::KQ470, 'bf-kq470-1027', '2026-10-27')],
        ];
    }

    public function test_days_run_from_today_minus_days_to_the_day_before_the_poll_window(): void
    {
        $now = CarbonImmutable::now('UTC');
        $this->assertSame(['2026-10-26', '2026-10-27'], Backfiller::days(3, $now));
        $this->assertCount(29, Backfiller::days(30, $now));
        $this->assertSame('2026-09-29', Backfiller::days(30, $now)[0]);
        $this->assertSame([], Backfiller::days(1, $now));
    }

    public function test_unknown_flights_get_one_version_and_one_first_seen_event_with_source_backfill(): void
    {
        $this->poll();
        $this->answersForTwoDays();
        $knownBefore = [count($this->versions(self::KQ478)), count($this->events(self::KQ478))];
        $eventsBefore = DB::table('change_events')->count();

        $this->assertSame(0, $this->backfill());

        $this->assertSame(['2026-10-26', '2026-10-27'], $this->fake->asked);
        Sleep::assertSequence([Sleep::for(2)->seconds()]);
        foreach (['bf-kq470-1026', 'bf-wb711-1026', 'bf-kq470-1027'] as $id) {
            $versions = $this->versions($id);
            $this->assertCount(1, $versions, $id);
            $this->assertSame(['backfill', null, 1], [$versions[0]->cause, $versions[0]->poll_id, (int) $versions[0]->version_no]);
            $events = $this->events($id);
            $this->assertCount(1, $events, $id);
            $this->assertSame(['first_seen', 'backfill', null], [$events[0]->kind, $events[0]->source, $events[0]->poll_id]);
            $this->assertMatchesRegularExpression('/^raw_responses:\d+$/', $events[0]->source_ref);
        }
        $this->assertSame($eventsBefore + 3, DB::table('change_events')->count());
        $this->assertSame(0, DB::table('change_events')->where('source', 'backfill')->where('kind', '<>', 'first_seen')->count());
        // Known flight untouched.
        $this->assertSame($knownBefore, [count($this->versions(self::KQ478)), count($this->events(self::KQ478))]);
        // Served values are corrected (NB0 → NBO), with no correction event.
        $flight = $this->flight('bf-kq470-1026');
        $this->assertSame(['NB0', 'NBO'], [$flight->adep_iata_aodb, $flight->adep_iata]);
        $this->assertSame('NBO', CanonicalJson::decode($this->events('bf-kq470-1026')[0]->new_value)->served->adepIata);
        // One raw answer per day, with that day's window.
        $raws = DB::table('raw_responses')->whereNotNull('request_window_from')->where('request_window_to', '<=', '2026-10-27 22:00:00')->orderBy('id')->get();
        $this->assertSame([['2026-10-25 22:00:00', '2026-10-26 22:00:00'], ['2026-10-26 22:00:00', '2026-10-27 22:00:00']],
            $raws->map(fn ($r) => [$r->request_window_from, $r->request_window_to])->all());
    }

    public function test_a_rerun_resumes_and_writes_nothing_twice(): void
    {
        $this->poll();
        $this->answersForTwoDays();
        $this->backfill();
        $counts = fn () => [DB::table('flights')->count(), DB::table('flight_versions')->count(), DB::table('change_events')->count(), DB::table('raw_responses')->count()];
        $before = $counts();
        $this->fake->asked = [];

        $this->assertSame(0, $this->backfill());

        $this->assertSame([], $this->fake->asked, 'written days are skipped without a call');
        $this->assertSame($before, $counts());
    }

    public function test_a_refused_past_window_stops_and_writes_nothing_more(): void
    {
        $this->poll();
        $this->answersForTwoDays();
        $this->fake->answers['2026-10-26'] = AodbResponse::fromFailure(AodbResponse::HTTP_ERROR, '/flights/v1/airport', 400, '{"error":"INVALID_DATE_RANGE"}');
        $before = [DB::table('flights')->count(), DB::table('raw_responses')->count()];

        $this->app->instance(AodbClient::class, $this->fake);
        $this->artisan('hub:backfill', ['--days' => 3])
            ->expectsOutputToContain('STOPPED at 2026-10-26: the AODB answered http_error (400 INVALID_DATE_RANGE). Nothing more was written.')
            ->assertExitCode(1);

        $this->assertSame(['2026-10-26'], $this->fake->asked);
        $this->assertSame($before, [DB::table('flights')->count(), DB::table('raw_responses')->count()]);
    }

    public function test_a_past_day_with_no_flights_stops_the_run(): void
    {
        $this->poll();
        $this->answersForTwoDays();
        $this->fake->answers['2026-10-26'] = [];

        $this->app->instance(AodbClient::class, $this->fake);
        $this->artisan('hub:backfill', ['--days' => 3])
            ->expectsOutputToContain('STOPPED at 2026-10-26: the AODB returned no flights for this past day.')
            ->assertExitCode(1);
        $this->assertSame(['2026-10-26'], $this->fake->asked);
        $this->assertFalse($this->known('bf-kq470-1027'));
    }

    public function test_rate_limited_or_login_backoff_stops_and_a_rerun_resumes(): void
    {
        $this->poll();
        $this->answersForTwoDays();
        $ok = $this->fake->answers['2026-10-27'];

        foreach ([AodbResponse::RATE_LIMITED, AodbResponse::LOGIN_BACKOFF] as $outcome) {
            $this->fake->answers['2026-10-27'] = AodbResponse::failed($outcome, '/flights/v1/airport', $outcome);
            $this->fake->asked = [];
            $this->assertSame(1, $this->backfill());
            $this->assertTrue($this->known('bf-kq470-1026'));
            $this->assertFalse($this->known('bf-kq470-1027'));
        }

        $this->fake->answers['2026-10-27'] = $ok;
        $this->fake->asked = [];
        $this->assertSame(0, $this->backfill());
        $this->assertSame(['2026-10-27'], $this->fake->asked);
        $this->assertTrue($this->known('bf-kq470-1027'));
    }

    public function test_the_backfill_needs_an_ok_poll_first_and_a_valid_days_option(): void
    {
        $this->app->instance(AodbClient::class, $this->fake);
        $this->artisan('hub:backfill')->expectsOutputToContain('no ok poll yet')->assertExitCode(1);
        $this->app->instance(AodbClient::class, $this->aodb);
        $this->poll();
        $this->app->instance(AodbClient::class, $this->fake);
        $this->artisan('hub:backfill', ['--days' => 0])->expectsOutputToContain('--days must be a whole number from 1 to 90')->assertExitCode(1);
        $this->artisan('hub:backfill', ['--days' => 91])->assertExitCode(1);
        $this->assertSame([], $this->fake->asked);
    }

    public function test_a_busy_change_events_lock_stops_the_day(): void
    {
        $this->poll();
        $this->answersForTwoDays();
        $other = DB::connection('mariadb_ddl');
        $other->selectOne('SELECT GET_LOCK(?, 0) AS l', [Poller::LOCK]);

        try {
            $result = (new Backfiller($this->fake, lockTimeout: 0))->run(3, CarbonImmutable::now('UTC'));
        } finally {
            $other->selectOne('SELECT RELEASE_LOCK(?) AS l', [Poller::LOCK]);
        }

        $this->assertSame('2026-10-26: the change events lock is busy (a poll is writing)', $result['stopped']);
        $this->assertFalse($this->known('bf-kq470-1026'));
    }

    public function test_backfilled_flights_are_not_evaluated_for_quality(): void
    {
        $this->poll();
        $this->answersForTwoDays();
        $this->backfill();

        // A window holding only backfilled flights: nothing is evaluated, no flag is opened.
        $result = (new QualityEvaluator)->evaluate(Window::kigaliDay('2026-10-26'), CarbonImmutable::now('UTC'));

        $this->assertSame(0, $result['evaluated']);
        $ids = DB::table('flights')->where('aodb_id', 'like', 'bf-%')->pluck('id');
        $this->assertSame(0, DB::table('quality_flags')->whereIn('flight_id', $ids)->count());
    }

    public function test_the_api_presents_the_first_seen_event_with_source_backfill(): void
    {
        $this->poll();
        $this->answersForTwoDays();
        $this->backfill();
        $key = $this->consumerKey(['route']);

        $response = $this->api('flights/bf-kq470-1026?history=1', $key)->assertOk();

        $this->assertSame([['first_seen', 'backfill']], array_map(fn ($e) => [$e['kind'], $e['source']], $response->json('history')));
    }
}
