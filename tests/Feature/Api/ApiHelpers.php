<?php

namespace Tests\Feature\Api;

use App\Api\ApiKeys;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * Consumers and keys for API tests (runtime user: consumers and api_keys are INSERT-able).
 */
trait ApiHelpers
{
    private int $consumers = 0;

    /**
     * @param  list<string>  $groups
     * @return string the full key
     */
    protected function consumerKey(array $groups, int $rate = 120, ?CarbonImmutable $expires = null, bool $active = true): string
    {
        $this->consumers++;
        $id = DB::table('consumers')->insertGetId([
            'code' => 'test-'.$this->consumers,
            'name' => 'Test consumer '.$this->consumers,
            'active' => $active,
            'field_groups' => json_encode($groups),
            'rate_limit_per_minute' => $rate,
            'created_by' => 'TEST',
        ]);

        return ApiKeys::issue($id, 'test key', $expires, 'TEST')['key'];
    }

    protected function api(string $uri, ?string $key, array $headers = []): TestResponse
    {
        return $this->getJson('/api/v1/'.$uri, ($key === null ? [] : ['Authorization' => 'Bearer '.$key]) + $headers);
    }

    protected const POLL_WINDOW = 'from=2026-10-27T22:00:00Z&to=2026-10-30T22:00:00Z';

    /**
     * @return array<string, mixed>
     */
    protected function flightIn(TestResponse $response, string $aodbId): array
    {
        foreach ($response->json('data') as $flight) {
            if ($flight['id'] === $aodbId) {
                return $flight;
            }
        }
        $this->fail("{$aodbId} not in the response.");
    }
}
