<?php

namespace Tests\Feature\ApiDocs;

use App\Quality\FlagResolver;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\ApiHelpers;
use Tests\Feature\Collector\PollTestCase;

/**
 * D2: real responses of every endpoint, on the 2026-10-29 sample, validate
 * against the OpenAPI document, for a consumer with every group and one with
 * identity only; so do 401, 404, 405, 422 and 429.
 */
class ApiContractTest extends PollTestCase
{
    use ApiHelpers;

    private const TK606_ARR_IST = 'bb7871bd-6796-4a27-a1fa-52dccc94b3a2';

    /** @var array<string, string> consumer → key */
    private array $keys = [];

    /**
     * The sample, then: a "same as" resolution (manual), an estimate change (aodb), a flight
     * leaving the feed (system), and coming back. Every kind and most sources of event.
     */
    private function history(): void
    {
        $this->keys = [
            'every group' => $this->consumerKey(array_keys(config('api_fields.groups')), 5000),
            'identity only' => $this->consumerKey([], 5000),
        ];
        $this->poll();

        $flag = DB::table('quality_flags')->where(['flight_id' => $this->flight(self::TK606_ARR_TK)->id, 'rule' => 'duplicate_suspected'])->sole();
        (new FlagResolver)->resolve($flag->id, 'same_as', 'Dany Kabalisa');

        $this->travel(5)->minutes();
        $this->aodb->scriptById(fn (array $ids) => []);
        $payload = self::without($this->sample(), self::WB711);
        self::record($payload, self::KQ470)->eldt = '2026-10-29T06:01:00Z';
        $this->poll($payload);

        $this->travel(5)->minutes();
        $this->poll($this->sample());
    }

    public function test_flights_validate_for_every_group_and_for_identity_only(): void
    {
        $this->history();

        foreach ($this->keys as $who => $key) {
            foreach ([
                'day=2026-10-29',
                self::POLL_WINDOW,
                self::POLL_WINDOW.'&flow=DEP',
                self::POLL_WINDOW.'&include_removed=1',
                self::POLL_WINDOW.'&as_of=2026-10-29T07:45:00Z',
                self::POLL_WINDOW.'&as_of=2026-10-29T07:00:00Z',
                'from=2026-10-29T05:00:00%2B02:00&to=2026-10-30T05:00:00%2B02:00',
            ] as $query) {
                $response = $this->api('flights?'.$query, $key)->assertOk();
                OpenApi::assertResponse($response, '/flights');
            }
            $this->assertNotSame([], $this->api('flights?'.self::POLL_WINDOW, $key)->json('data'), $who);
        }
    }

    public function test_each_flight_and_its_history_validate(): void
    {
        $this->history();
        $ids = DB::table('flights')->pluck('aodb_id');
        $this->assertGreaterThan(40, $ids->count());

        foreach ($this->keys as $key) {
            foreach ($ids as $id) {
                OpenApi::assertResponse($this->api("flights/{$id}?history=1", $key)->assertOk(), '/flights/{aodbId}');
            }
            OpenApi::assertResponse($this->api('flights/'.self::KQ470, $key)->assertOk(), '/flights/{aodbId}');
        }
    }

    public function test_changes_validate_page_by_page_to_the_head(): void
    {
        $this->history();
        $all = DB::table('change_events')->count();
        $kinds = DB::table('change_events')->distinct()->pluck('kind')->sort()->values()->all();
        $this->assertSame(['field_changed', 'first_seen', 'removed_from_feed', 'returned_to_feed'], $kinds);
        $this->assertEqualsCanonicalizing(['aodb', 'correction', 'manual', 'system'], DB::table('change_events')->distinct()->pluck('source')->all());

        foreach ($this->keys as $who => $key) {
            $cursor = 0;
            $seen = 0;
            do {
                $response = $this->api("changes?cursor={$cursor}&limit=7", $key)->assertOk();
                OpenApi::assertResponse($response, '/changes');
                $seen += count($response->json('data'));
                $cursor = $response->json('meta.nextCursor');
            } while ($response->json('meta.hasMore'));

            OpenApi::assertResponse($this->api("changes?cursor={$cursor}", $key)->assertOk(), '/changes');
            // A limit above 1000 is reduced to 1000, as the document says (Batch 9b, D5).
            $big = $this->api('changes?cursor=0&limit=5000', $key)->assertOk();
            OpenApi::assertResponse($big, '/changes');
            $this->assertSame(1000, $big->json('meta.limit'));
            $who === 'every group' ? $this->assertSame($all, $seen) : $this->assertLessThan($all, $seen);
        }
    }

    public function test_health_validates_before_the_first_poll_and_after(): void
    {
        $key = $this->consumerKey([]);

        $before = $this->api('health', $key)->assertOk();
        OpenApi::assertResponse($before, '/health');
        $this->assertSame(['asOf' => null, 'feed' => 'stale', 'lastPoll' => null], $before->json('data'));

        $this->poll();
        OpenApi::assertResponse($this->api('health', $key)->assertOk(), '/health');
    }

    public function test_401_404_405_and_422_validate(): void
    {
        $key = $this->consumerKey([]);
        $this->poll();

        foreach (['/flights' => 'flights?day=2026-10-29', '/flights/{aodbId}' => 'flights/'.self::KQ470, '/changes' => 'changes?cursor=0', '/health' => 'health'] as $path => $uri) {
            OpenApi::assertResponse($this->api($uri, null)->assertStatus(401), $path);
            OpenApi::assertResponse($this->api($uri, 'fdh_abcdefgh_wrong')->assertStatus(401), $path);
            OpenApi::assertResponse($this->postJson('/api/v1/'.$uri, [], ['Authorization' => 'Bearer '.$key])->assertStatus(405), $path);
        }

        OpenApi::assertResponse($this->api('flights/0af6b9d4-0000-0000-0000-000000000000', $key)->assertNotFound(), '/flights/{aodbId}');
        OpenApi::assertAgainst($this->api('nowhere', $key)->assertNotFound(), OpenApi::pointer('components', 'responses', 'NotFound'), 'unknown path');

        foreach ([
            'flights' => '/flights',
            'flights?day=29-10-2026' => '/flights',
            'flights?day=2026-10-29&from=2026-10-29T00:00:00Z&to=2026-10-30T00:00:00Z' => '/flights',
            'flights?from=2026-10-20T00:00:00Z&to=2026-10-29T00:00:00Z' => '/flights',
            'flights?from=2026-10-29T00:00:00&to=2026-10-30T00:00:00Z' => '/flights',
            'flights?day=2026-10-29&flow=ARRIVAL' => '/flights',
            'flights?day=2026-10-29&as_of=yesterday' => '/flights',
            'flights?day=2026-10-29&include_removed=yes' => '/flights',
            'flights/'.self::KQ470.'?history=yes' => '/flights/{aodbId}',
            'changes' => '/changes',
            'changes?cursor=-1' => '/changes',
            'changes?cursor=0&limit=0' => '/changes',
        ] as $uri => $path) {
            OpenApi::assertResponse($this->api($uri, $key)->assertStatus(422), $path);
        }
    }

    public function test_429_validates_with_retry_after(): void
    {
        $this->poll();

        foreach (['/flights' => 'flights?day=2026-10-29', '/flights/{aodbId}' => 'flights/'.self::KQ470, '/changes' => 'changes?cursor=0', '/health' => 'health'] as $path => $uri) {
            $key = $this->consumerKey([], 1);
            $this->api($uri, $key)->assertOk();
            $limited = $this->api($uri, $key)->assertStatus(429);
            OpenApi::assertResponse($limited, $path);
            $this->assertGreaterThanOrEqual(1, (int) $limited->headers->get('Retry-After'));
        }

        // Refused calls from one IP address: the 31st in a minute gets 429.
        for ($i = 0; $i < 30; $i++) {
            $this->api('health', null)->assertStatus(401);
        }
        OpenApi::assertResponse($this->api('health', null)->assertStatus(429), '/health');
    }
}
