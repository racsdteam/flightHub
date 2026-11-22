<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\ApiDocs\OpenApi;
use Tests\Feature\ApiDocs\PublicOpenApi;
use Tests\Feature\Collector\PollTestCase;

/**
 * Batch 7d (D-062): the API over plain HTTP, in production, is 403 HTTPS_REQUIRED, before any key
 * is read or looked up, and never a redirect. Over HTTPS, and outside production, nothing changes.
 */
class HttpsOnlyTest extends PollTestCase
{
    use ApiHelpers;

    private const REFUSED = ['error' => 'HTTPS_REQUIRED', 'message' => 'HTTPS required.'];

    /** @var list<string> */
    private array $queries = [];

    private function request(string $scheme, string $path, ?string $key, array $headers = []): TestResponse
    {
        $this->queries = [];
        DB::listen(fn ($query) => $this->queries[] = $query->sql);

        return $this->getJson("{$scheme}://localhost/api/v1/{$path}", ($key === null ? [] : ['Authorization' => 'Bearer '.$key]) + $headers);
    }

    public function test_plain_http_in_production_is_refused_before_any_key_lookup_and_never_redirected(): void
    {
        $this->poll();
        $key = $this->consumerKey(['route']);
        $this->app['env'] = 'production';

        foreach ([['health', $key], ['flights?'.self::POLL_WINDOW, $key], ['flights/'.self::WB711, null], ['changes', 'fdh_wrongkey_x'], ['nowhere', $key]] as [$path, $sent]) {
            $response = $this->request('http', $path, $sent)->assertStatus(403)->assertExactJson(self::REFUSED);

            $response->assertHeaderMissing('Location');
            $this->assertSame([], preg_grep('/api_keys|consumers/', $this->queries), "{$path}: no key lookup");
            $call = DB::table('api_calls')->orderByDesc('id')->first();
            $this->assertSame([null, null, 403, 'HTTPS_REQUIRED'], [$call->consumer_id, $call->api_key_id, $call->status, $call->error_code], $path);
        }
        $this->assertSame(0, DB::table('api_keys')->whereNotNull('last_used_at')->count(), 'the key was never used');

        // A refusal both documents describe.
        $response = $this->request('http', 'health', $key);
        OpenApi::assertResponse($response, '/health');
        PublicOpenApi::assertResponse($this->request('http', 'flights?'.self::POLL_WINDOW, $key), '/flights');
        PublicOpenApi::assertResponse($this->request('http', 'flights/'.self::WB711, $key), '/flights/{id}');
    }

    public function test_https_in_production_is_unchanged(): void
    {
        $this->poll();
        $key = $this->consumerKey(['route']);
        $this->app['env'] = 'production';

        $this->request('https', 'health', $key)->assertOk();
        $this->request('https', 'health', null)->assertUnauthorized()->assertExactJson(['error' => 'UNAUTHORIZED', 'message' => 'A valid API key is required.']);
        // What public/.htaccess accepts as HTTPS: a TLS proxy's X-Forwarded-SSL: on.
        $this->request('http', 'health', $key, ['X-Forwarded-SSL' => 'on'])->assertOk();
    }

    public function test_plain_http_outside_production_is_unchanged(): void
    {
        $this->poll();
        $key = $this->consumerKey(['route']);

        foreach (['local', 'testing'] as $environment) {
            $this->app['env'] = $environment;
            $this->request('http', 'health', $key)->assertOk();
            $this->request('http', 'health', null)->assertUnauthorized();
        }
    }
}
