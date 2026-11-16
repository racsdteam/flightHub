<?php

namespace Tests\Feature\Api;

use Tests\Feature\Collector\PollTestCase;

/**
 * CORS is off (config/cors.php): no response of the API or of its documentation carries
 * Access-Control-Allow-Origin, for a simple request or a preflight, with or without a key.
 */
class CorsTest extends PollTestCase
{
    use ApiHelpers;

    private const ORIGIN = ['Origin' => 'https://another-site.example'];

    public function test_no_path_is_shared_with_other_origins(): void
    {
        $this->assertSame([], config('cors.paths'));
        $this->assertSame([], config('cors.allowed_origins'));
    }

    public function test_api_responses_carry_no_cors_header(): void
    {
        $this->poll();
        $key = $this->consumerKey(['route']);

        foreach (['health', 'flights?day=2026-10-29', 'changes?cursor=0', 'flights?day=bad'] as $uri) {
            $this->api($uri, $key, self::ORIGIN)->assertHeaderMissing('Access-Control-Allow-Origin')->assertHeaderMissing('Access-Control-Allow-Credentials');
            $this->api($uri, null, self::ORIGIN)->assertStatus(401)->assertHeaderMissing('Access-Control-Allow-Origin');
        }

        // A preflight gets no permission either.
        $preflight = $this->call('OPTIONS', '/api/v1/flights', [], [], [], $this->transformHeadersToServerVars(self::ORIGIN + [
            'Access-Control-Request-Method' => 'GET',
            'Access-Control-Request-Headers' => 'authorization',
        ]));
        $preflight->assertHeaderMissing('Access-Control-Allow-Origin')
            ->assertHeaderMissing('Access-Control-Allow-Methods')
            ->assertHeaderMissing('Access-Control-Allow-Headers');
    }

    public function test_the_documentation_carries_no_cors_header(): void
    {
        foreach (['/api/docs', '/api/openapi.yaml'] as $uri) {
            $this->get($uri, self::ORIGIN)->assertOk()->assertHeaderMissing('Access-Control-Allow-Origin');
        }
    }
}
