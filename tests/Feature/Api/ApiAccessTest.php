<?php

namespace Tests\Feature\Api;

use App\Api\ApiKeys;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Collector\PollTestCase;

/**
 * G1: every refusal is the same 401, logged without the key; rate limits.
 */
class ApiAccessTest extends PollTestCase
{
    use ApiHelpers;

    private const UNAUTHORIZED = ['error' => 'UNAUTHORIZED', 'message' => 'A valid API key is required.'];

    public function test_every_refusal_is_the_same_401_and_is_logged_without_the_key(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message.json_encode($e->context);
        });
        $good = $this->consumerKey(['route']);
        [$prefix] = explode('_', substr($good, 4), 2);
        $revoked = $this->consumerKey(['route']);
        DB::table('api_keys')->where('prefix', substr($revoked, 0, 12))->update(['revoked_at' => '2026-10-29 07:00:00', 'revoked_by' => 'TEST']);
        $expired = $this->consumerKey(['route'], expires: now()->toImmutable()->addMinute());
        $inactive = $this->consumerKey(['route'], active: false);
        $this->travel(2)->minutes();

        $attempts = [
            'no header' => [null, []],
            'malformed' => [null, ['Authorization' => 'Bearer not-a-key']],
            'basic scheme' => [null, ['Authorization' => 'Basic '.base64_encode('x:'.$good)]],
            'unknown prefix' => ['fdh_ZZZZZZZZ_'.str_repeat('a', 40), []],
            'wrong secret' => ['fdh_'.$prefix.'_'.str_repeat('b', 40), []],
            'revoked' => [$revoked, []],
            'expired' => [$expired, []],
            'inactive consumer' => [$inactive, []],
        ];
        foreach ($attempts as $case => [$key, $headers]) {
            $this->api('health', $key, $headers)->assertStatus(401)->assertExactJson(self::UNAUTHORIZED);
        }
        // A key only in the query string is ignored.
        $this->getJson('/api/v1/health?api_key='.$good.'&key='.$good.'&token='.$good)->assertStatus(401)->assertExactJson(self::UNAUTHORIZED);

        $calls = DB::table('api_calls')->orderBy('id')->get();
        $this->assertCount(9, $calls);
        $this->assertTrue($calls->every(fn ($c) => $c->status === 401 && $c->error_code === 'UNAUTHORIZED' && $c->consumer_id === null && $c->api_key_id === null));
        $this->assertSame('/api/v1/health', $calls->last()->path);
        $this->assertNull($calls->last()->params, 'api_key/key/token are not allow-listed parameters');

        $haystack = json_encode($calls).implode("\n", $logged);
        foreach ([$good, $revoked, $expired, $inactive] as $key) {
            $this->assertStringNotContainsString(substr($key, 13), $haystack);
        }
        $this->assertStringNotContainsString('Bearer', $haystack);
    }

    public function test_a_valid_key_is_accepted_and_logged_with_its_consumer(): void
    {
        $key = $this->consumerKey(['route']);
        $this->poll();

        $this->api('health', $key)->assertOk()->assertJsonPath('data.lastPoll.outcome', 'ok');

        $call = DB::table('api_calls')->sole();
        $keyRow = DB::table('api_keys')->where('prefix', substr($key, 0, 12))->sole();
        $this->assertSame([(int) $keyRow->consumer_id, (int) $keyRow->id, 200, null], [(int) $call->consumer_id, (int) $call->api_key_id, (int) $call->status, $call->error_code]);
        $this->assertSame('2026-10-29 07:42:30', $keyRow->last_used_at);
    }

    public function test_last_used_at_is_updated_at_most_once_a_minute(): void
    {
        $key = $this->consumerKey(['route']);
        $this->api('health', $key)->assertOk();
        $this->travel(30)->seconds();
        $this->api('health', $key)->assertOk();
        $this->assertSame('2026-10-29 07:42:30', DB::table('api_keys')->value('last_used_at'));

        $this->travel(31)->seconds();
        $this->api('health', $key)->assertOk();
        $this->assertSame('2026-10-29 07:43:31', DB::table('api_keys')->value('last_used_at'));
    }

    public function test_rate_limit_per_key_is_429_with_retry_after(): void
    {
        $key = $this->consumerKey(['route'], rate: 2);

        $this->api('health', $key)->assertOk();
        $this->api('health', $key)->assertOk();
        $response = $this->api('health', $key)->assertStatus(429)->assertExactJson(['error' => 'RATE_LIMITED', 'message' => 'Too many requests.']);

        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        $this->assertSame('RATE_LIMITED', DB::table('api_calls')->orderByDesc('id')->value('error_code'));

        $this->travel(61)->seconds();
        $this->api('health', $key)->assertOk();
    }

    public function test_refused_calls_are_limited_per_ip(): void
    {
        $good = $this->consumerKey(['route']);
        foreach (range(1, 30) as $i) {
            $this->api('health', 'fdh_AAAAAAAA_'.str_repeat('c', 40))->assertStatus(401);
        }

        $this->api('health', 'fdh_AAAAAAAA_'.str_repeat('c', 40))->assertStatus(429)->assertHeader('Retry-After');
        $this->api('health', null)->assertStatus(429);
        // A valid key is never blocked by the IP limit (the key is checked first).
        $this->api('health', $good)->assertOk();
    }

    public function test_wrong_method_calls_are_logged_like_any_other_call(): void
    {
        $key = $this->consumerKey(['route']);

        $this->postJson('/api/v1/flights', [], ['Authorization' => 'Bearer '.$key])
            ->assertStatus(405)->assertHeader('Allow', 'GET, HEAD')
            ->assertExactJson(['error' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed.']);
        $this->deleteJson('/api/v1/changes')->assertStatus(401);

        $calls = DB::table('api_calls')->orderBy('id')->get();
        $this->assertSame([['POST', '/api/v1/flights', 405, 'METHOD_NOT_ALLOWED'], ['DELETE', '/api/v1/changes', 401, 'UNAUTHORIZED']],
            $calls->map(fn ($c) => [$c->method, $c->path, (int) $c->status, $c->error_code])->all());
        $this->assertNotNull($calls[0]->consumer_id);
    }

    public function test_errors_are_json_without_stack_trace_even_in_debug(): void
    {
        config(['app.debug' => true]);
        $key = $this->consumerKey(['route']);

        $this->api('nowhere', $key)->assertStatus(404)->assertExactJson(['error' => 'NOT_FOUND', 'message' => 'Not found.']);
        $this->api('flights?day=yesterday', $key)->assertStatus(422)->assertJsonPath('error', 'INVALID_PARAMETER');
        $this->postJson('/api/v1/flights', [], ['Authorization' => 'Bearer '.$key])
            ->assertStatus(405)->assertExactJson(['error' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed.']);
    }

    public function test_the_full_key_is_stored_only_as_its_hash(): void
    {
        $key = $this->consumerKey(['route']);
        $row = DB::table('api_keys')->sole();

        $this->assertSame(ApiKeys::hash($key), $row->secret_hash);
        $this->assertSame(substr($key, 0, 12), $row->prefix);
        $this->assertStringNotContainsString(substr($key, 13), json_encode($row));
    }
}
