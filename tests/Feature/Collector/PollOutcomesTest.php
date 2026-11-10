<?php

namespace Tests\Feature\Collector;

use App\Aodb\AodbClient;
use App\Aodb\AodbResponse;
use App\Collector\Poller;
use Illuminate\Support\Facades\DB;

/**
 * Warnings and the outcomes that write no flight data.
 */
class PollOutcomesTest extends PollTestCase
{
    public function test_missing_id_duplicate_id_and_unknown_fields_are_warnings(): void
    {
        $payload = $this->sample();
        $noId = clone self::record($payload, self::WB711);
        unset($noId->id);
        $duplicate = clone self::record($payload, self::WB711);
        $duplicate->status = 'CANCELLED';
        self::record($payload, self::KQ470)->newField = 'x';
        self::record($payload, self::KQ478)->newField = 'y';
        self::record($payload, self::KQ478)->aircraftDetails->seats = 96;
        $payload->flights[] = $noId;
        $payload->flights[] = $duplicate;

        $poll = $this->poll($payload);

        $this->assertSame('ok', $poll->outcome);
        $this->assertSame(53, (int) $poll->flights_in_payload);
        $this->assertSame(51, (int) $poll->flights_new);
        $this->assertSame('IN_FLIGHT', $this->flight(self::WB711)->status);
        $this->assertSame([
            ['code' => 'missing_id', 'detail' => 'flights[51]'],
            ['code' => 'duplicate_id', 'aodb_id' => self::WB711, 'detail' => 'flights[52] skipped; first kept'],
            // KQ478 comes before KQ470 in the sample; each path is listed once per poll.
            ['code' => 'unknown_field', 'aodb_id' => self::KQ478, 'detail' => 'aircraftDetails.seats'],
            ['code' => 'unknown_field', 'aodb_id' => self::KQ478, 'detail' => 'newField'],
        ], json_decode($poll->warnings, true));
        // The unknown values are kept in the record.
        $this->assertSame('x', json_decode($this->flight(self::KQ470)->record)->newField);
    }

    public function test_unparsable_body_is_invalid_payload_and_kept(): void
    {
        $poll = $this->poll('{"flights": [ not json');

        $this->assertSame('invalid_payload', $poll->outcome);
        $this->assertSame('200 UNPARSABLE_BODY', $poll->error_summary);
        $raw = DB::table('raw_responses')->sole();
        $this->assertSame('{"flights": [ not json', gzdecode($raw->body));
        $this->assertSame((int) $raw->id, (int) $poll->raw_response_id);
        $this->assertSame(0, DB::table('flights')->count());
    }

    public function test_body_without_flights_array_is_invalid_payload(): void
    {
        $poll = $this->poll('{"items": []}');

        $this->assertSame('invalid_payload', $poll->outcome);
        $this->assertSame('200 NO_FLIGHTS_ARRAY', $poll->error_summary);
        $this->assertSame(1, DB::table('raw_responses')->count());
    }

    public function test_non_2xx_writes_a_poll_row_and_nothing_else(): void
    {
        $this->aodb->queueAirport(AodbResponse::fromFailure(AodbResponse::HTTP_ERROR, '/flights/v1/airport', 500, '{"error":"X","message":"secret detail"}'));

        $poll = $this->poll();

        $this->assertSame('http_error', $poll->outcome);
        $this->assertSame(500, (int) $poll->http_status);
        $this->assertSame('500 X', $poll->error_summary);
        $this->assertSame([0, 0], [DB::table('raw_responses')->count(), DB::table('flights')->count()]);
    }

    public function test_lock_timeout_writes_nothing_and_the_next_poll_retries(): void
    {
        $other = DB::connection('mariadb_ddl');
        $this->assertSame(1, (int) $other->selectOne('SELECT GET_LOCK(?, 0) AS l', [Poller::LOCK])->l);

        try {
            $poll = (object) (new Poller(fn () => $this->aodb, lockTimeout: 0))->run();
        } finally {
            $other->selectOne('SELECT RELEASE_LOCK(?) AS l', [Poller::LOCK]);
        }

        $this->assertSame('lock_timeout', $poll->outcome);
        $this->assertSame([0, 0, 0], [DB::table('raw_responses')->count(), DB::table('flights')->count(), DB::table('change_events')->count()]);

        $this->assertSame(51, (int) $this->poll()->flights_new);
    }

    public function test_fixture_client_is_refused_in_production(): void
    {
        $this->app->forgetInstance(AodbClient::class);
        $this->app['env'] = 'production';
        config(['aodb.client' => 'fixture']);

        $poll = $this->poll();

        $this->assertSame('config_error', $poll->outcome);
        $this->assertSame('AODB_CLIENT=fixture is refused in production', $poll->error_summary);
    }

    public function test_window_longer_than_seven_days_is_a_config_error(): void
    {
        config(['aodb.window_days_before' => 4, 'aodb.window_days_after' => 3]);

        $poll = $this->poll();

        $this->assertSame('config_error', $poll->outcome);
        $this->assertSame(0, DB::table('flights')->count());
    }
}
