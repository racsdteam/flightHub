<?php

namespace Tests\Feature\Aodb;

use App\Aodb\AodbClient;
use App\Aodb\HttpAodbClient;
use App\Flights\Window;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The HTTP client against Http::fake() only (R8). Credentials and tokens here
 * are FAKE.
 */
class HttpAodbClientTest extends TestCase
{
    private const PASSWORD = 'FAKE-Passw0rd-not-real';

    private const USERNAME = 'ops@rac.test';

    private const LOGIN = 'aodb.test/login';

    private const AIRPORT = 'aodb.test/flights/v1/airport*';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-29 07:42:30');
        config([
            'aodb.client' => 'http',
            'aodb.base_url' => 'https://aodb.test',
            'aodb.username' => self::USERNAME,
            'aodb.password' => self::PASSWORD,
            'aodb.airport' => 'HRYR',
        ]);
    }

    private function client(): HttpAodbClient
    {
        return new HttpAodbClient(config('aodb'));
    }

    private function window(): Window
    {
        return Window::kigaliDay('2026-10-29');
    }

    /**
     * A FAKE JWT whose exp is $ttl seconds from now.
     */
    private static function jwt(int $ttl = 3600, string $sub = 'fake'): string
    {
        $b64 = fn (array $data) => rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');

        return $b64(['alg' => 'HS256', 'typ' => 'JWT']).'.'.$b64(['sub' => $sub, 'exp' => now()->getTimestamp() + $ttl]).'.FAKEsignatureFAKE';
    }

    private static function loginOk(string $token, bool $bearer = true): mixed
    {
        return Http::response(['username' => self::USERNAME, 'airports' => ['HRYR']], 200, ['Authorization' => ($bearer ? 'Bearer ' : '').$token]);
    }

    private static function error(int $status, string $key, string $message = 'server says something'): mixed
    {
        return Http::response(['error' => $key, 'message' => $message, 'path' => '/x', 'status' => $status, 'timestamp' => 1761723750000], $status);
    }

    private function loginCount(): int
    {
        return Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/login'))->count();
    }

    private function dataGetCount(): int
    {
        return Http::recorded(fn (Request $r) => $r->method() === 'GET')->count();
    }

    public function test_login_body_bearer_token_from_response_header_and_query(): void
    {
        $token = self::jwt();
        Http::fake([self::LOGIN => self::loginOk($token), self::AIRPORT => Http::response(['flights' => []])]);

        $response = $this->client()->flightsAtAirport($this->window());

        $this->assertSame('ok', $response->outcome);
        $this->assertSame([], $response->flights);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'https://aodb.test/login'
            && $r->data() === ['username' => self::USERNAME, 'password' => self::PASSWORD]);
        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && $r->url() === 'https://aodb.test/flights/v1/airport?airport=HRYR&from=2026-10-28T22%3A00%3A00Z&to=2026-10-29T22%3A00%3A00Z'
            && $r->header('Authorization') === ['Bearer '.$token]);
    }

    public function test_token_without_bearer_prefix_in_the_login_header(): void
    {
        $token = self::jwt();
        Http::fake([self::LOGIN => self::loginOk($token, bearer: false), self::AIRPORT => Http::response(['flights' => []])]);

        $this->assertSame('ok', $this->client()->flightsAtAirport($this->window())->outcome);
        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r->header('Authorization') === ['Bearer '.$token]);
    }

    public function test_token_is_cached_encrypted_and_reused_until_exp_minus_60s(): void
    {
        $token = self::jwt(600);
        Http::fake([self::LOGIN => self::loginOk($token), self::AIRPORT => Http::response(['flights' => []])]);

        $this->client()->flightsAtAirport($this->window());
        $cached = Cache::get(HttpAodbClient::TOKEN_KEY);
        $this->assertIsString($cached);
        $this->assertStringNotContainsString($token, $cached);
        $this->assertStringNotContainsString(explode('.', $token)[1], $cached);
        $this->assertSame($token, Crypt::decryptString($cached));

        $this->travel(539)->seconds();
        $this->client()->flightsAtAirport($this->window());
        $this->assertSame(1, $this->loginCount());

        $this->travel(2)->seconds();
        $this->client()->flightsAtAirport($this->window());
        $this->assertSame(2, $this->loginCount());
    }

    public function test_unreadable_token_is_kept_30_minutes(): void
    {
        Http::fake([self::LOGIN => self::loginOk('opaque-FAKE-token'), self::AIRPORT => Http::response(['flights' => []])]);

        $this->client()->flightsAtAirport($this->window());
        $this->travel(29)->minutes();
        $this->client()->flightsAtAirport($this->window());
        $this->assertSame(1, $this->loginCount());

        $this->travel(2)->minutes();
        $this->client()->flightsAtAirport($this->window());
        $this->assertSame(2, $this->loginCount());
    }

    public function test_token_expired_means_one_relogin_and_one_retry(): void
    {
        $first = self::jwt(sub: 'first');
        $second = self::jwt(sub: 'second');
        Http::fake([
            self::LOGIN => Http::sequence()->push(...self::args(self::loginOk($first)))->push(...self::args(self::loginOk($second))),
            self::AIRPORT => Http::sequence()->pushResponse(self::error(401, 'TOKEN_EXPIRED'))->push(['flights' => []]),
        ]);

        $response = $this->client()->flightsAtAirport($this->window());

        $this->assertSame('ok', $response->outcome);
        $this->assertSame([2, 2], [$this->loginCount(), $this->dataGetCount()]);
        $gets = Http::recorded(fn (Request $r) => $r->method() === 'GET')->map(fn ($pair) => $pair[0]->header('Authorization')[0])->values()->all();
        $this->assertSame(['Bearer '.$first, 'Bearer '.$second], $gets);
    }

    public function test_token_expired_twice_is_auth_error(): void
    {
        Http::fake([self::LOGIN => self::loginOk(self::jwt()), self::AIRPORT => self::error(401, 'TOKEN_EXPIRED')]);

        $response = $this->client()->flightsAtAirport($this->window());

        $this->assertSame(['auth_error', '401 TOKEN_EXPIRED'], [$response->outcome, $response->error]);
        $this->assertSame([2, 2], [$this->loginCount(), $this->dataGetCount()]);
    }

    public function test_other_401_key_is_auth_error_without_relogin(): void
    {
        Http::fake([self::LOGIN => self::loginOk(self::jwt()), self::AIRPORT => self::error(401, 'USER_INACTIVE')]);

        $response = $this->client()->flightsAtAirport($this->window());

        $this->assertSame(['auth_error', '401 USER_INACTIVE'], [$response->outcome, $response->error]);
        $this->assertSame([1, 1], [$this->loginCount(), $this->dataGetCount()]);
    }

    public function test_wrong_password_backs_off_15_minutes_without_login(): void
    {
        Http::fake([self::LOGIN => self::error(401, 'WRONG_PASSWORD'), self::AIRPORT => Http::response(['flights' => []])]);

        $first = $this->client()->flightsAtAirport($this->window());
        $this->assertSame(['auth_error', '401 WRONG_PASSWORD'], [$first->outcome, $first->error]);

        $this->travel(14)->minutes();
        $second = $this->client()->flightsAtAirport($this->window());
        $this->assertSame('login_backoff', $second->outcome);
        $this->assertSame([1, 0], [$this->loginCount(), $this->dataGetCount()]);

        $this->travel(61)->seconds();
        $this->client()->flightsAtAirport($this->window());
        $this->assertSame(2, $this->loginCount());
    }

    public function test_wrong_payload_on_login_also_backs_off(): void
    {
        Http::fake([self::LOGIN => self::error(400, 'WRONG_PAYLOAD')]);

        $this->assertSame('auth_error', $this->client()->flightsAtAirport($this->window())->outcome);
        $this->assertSame('login_backoff', $this->client()->flightsAtAirport($this->window())->outcome);
        $this->assertSame(1, $this->loginCount());
    }

    public function test_403_is_forbidden(): void
    {
        Http::fake([self::LOGIN => self::loginOk(self::jwt()), self::AIRPORT => self::error(403, 'AIRPORT_ACCESS_NOT_ALLOWED')]);

        $response = $this->client()->flightsAtAirport($this->window());

        $this->assertSame(['forbidden', '403 AIRPORT_ACCESS_NOT_ALLOWED'], [$response->outcome, $response->error]);
    }

    public function test_timeout_is_timeout(): void
    {
        Http::fake([
            self::LOGIN => self::loginOk(self::jwt()),
            self::AIRPORT => Http::failedConnection('cURL error 28: Operation timed out after 30001 milliseconds'),
        ]);

        $response = $this->client()->flightsAtAirport($this->window());

        $this->assertSame(['timeout', 'timeout'], [$response->outcome, $response->error]);
    }

    public function test_500_is_http_error_without_body(): void
    {
        Http::fake([self::LOGIN => self::loginOk(self::jwt()), self::AIRPORT => Http::response('<html>proxy error at /flights/v1/airport?from=x</html>', 500)]);

        $response = $this->client()->flightsAtAirport($this->window());

        $this->assertSame(['http_error', 500, '500'], [$response->outcome, $response->httpStatus, $response->error]);
        $this->assertNull($response->body);
        $this->assertFalse($response->hasBody());
    }

    public function test_error_summary_is_status_and_key_never_the_message(): void
    {
        Http::fake([self::LOGIN => self::loginOk(self::jwt()), self::AIRPORT => self::error(401, 'USER_EXPIRED', 'User '.self::USERNAME.' expired; request /flights/v1/airport?airport=HRYR')]);

        $response = $this->client()->flightsAtAirport($this->window());

        $this->assertSame('401 USER_EXPIRED', $response->error);
    }

    public function test_byid_sends_comma_joined_ids(): void
    {
        Http::fake([self::LOGIN => self::loginOk(self::jwt()), 'aodb.test/flights/v1/byid*' => Http::response(['flights' => []])]);

        $this->assertSame('ok', $this->client()->flightsById(['a-1', 'b-2'])->outcome);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://aodb.test/flights/v1/byid?airport=HRYR&ids=a-1%2Cb-2');
    }

    public function test_missing_credentials_are_a_config_error_and_nothing_is_sent(): void
    {
        Http::fake();
        config(['aodb.password' => null]);

        $response = $this->client()->flightsAtAirport($this->window());

        $this->assertSame(['config_error', 'AODB_USERNAME or AODB_PASSWORD missing'], [$response->outcome, $response->error]);
        Http::assertNothingSent();
    }

    public function test_plain_http_base_url_is_a_config_error(): void
    {
        Http::fake();
        config(['aodb.base_url' => 'http://aodb.test']);

        $this->assertSame('config_error', $this->client()->flightsAtAirport($this->window())->outcome);
        Http::assertNothingSent();
    }

    public function test_poll_with_500_stores_no_raw(): void
    {
        $this->app->forgetInstance(AodbClient::class);
        Http::fake([self::LOGIN => self::loginOk(self::jwt()), self::AIRPORT => Http::response('oops', 500)]);

        $this->assertSame(0, $this->artisan('hub:poll')->run());

        $poll = DB::table('polls')->sole();
        $this->assertSame(['http_error', '500'], [$poll->outcome, $poll->error_summary]);
        $this->assertSame(0, DB::table('raw_responses')->count());
    }

    public function test_poll_with_missing_credentials_is_config_error_without_request(): void
    {
        $this->app->forgetInstance(AodbClient::class);
        config(['aodb.username' => '']);
        Http::fake();

        $this->assertSame(0, $this->artisan('hub:poll')->run());

        $this->assertSame('config_error', DB::table('polls')->sole()->outcome);
        Http::assertNothingSent();
    }

    public function test_password_and_token_never_reach_logs_polls_raw_or_errors(): void
    {
        $this->app->forgetInstance(AodbClient::class);
        $token = self::jwt(sub: 'secret-subject');
        $sample = (string) file_get_contents(base_path('tests/Fixtures/aodb/HRYR-2026-10-29T074230Z.json'));
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message.' '.json_encode($e->context);
        });
        Http::fake([
            self::LOGIN => Http::sequence()
                ->push(...self::args(self::loginOk($token)))
                ->push(...self::args(self::loginOk($token)))
                ->pushResponse(self::error(401, 'WRONG_PASSWORD', 'bad password '.self::PASSWORD)),
            self::AIRPORT => Http::sequence()
                ->push($sample, 200)
                ->pushResponse(self::error(401, 'TOKEN_EXPIRED', 'token '.$token.' expired'))
                ->pushResponse(self::error(401, 'TOKEN_EXPIRED', 'token '.$token.' expired'))
                ->pushResponse(self::error(401, 'TOKEN_EXPIRED', 'token '.$token.' expired')),
        ]);

        // 1: login, ok. 2: TOKEN_EXPIRED → re-login → TOKEN_EXPIRED again: auth_error.
        // 3: TOKEN_EXPIRED → re-login answered WRONG_PASSWORD: auth_error. 4: back-off, no request.
        foreach (range(1, 4) as $i) {
            $this->assertSame(0, $this->artisan('hub:poll')->run());
            $this->travel(1)->minutes();
        }

        $this->assertSame(['ok', 'auth_error', 'auth_error', 'login_backoff'], DB::table('polls')->orderBy('id')->pluck('outcome')->all());

        $haystacks = [
            ...$logged,
            json_encode(DB::table('polls')->get()),
            json_encode(DB::table('change_events')->get()),
            DB::table('flights')->pluck('record')->implode("\n"),
            ...DB::table('raw_responses')->pluck('body')->map(fn ($gz) => gzdecode($gz))->all(),
            (string) Cache::get(HttpAodbClient::TOKEN_KEY),
        ];
        foreach ($haystacks as $haystack) {
            $this->assertStringNotContainsString(self::PASSWORD, $haystack);
            $this->assertStringNotContainsString($token, $haystack);
            $this->assertStringNotContainsString(explode('.', $token)[1], $haystack);
            $this->assertStringNotContainsString('Bearer', $haystack);
        }
    }

    /**
     * Http::response() returns a promise; sequences take body, status, headers.
     */
    private static function args(mixed $promise): array
    {
        $response = $promise->wait();

        return [(string) $response->getBody(), $response->getStatusCode(), array_map(fn ($v) => implode(', ', $v), $response->getHeaders())];
    }
}
