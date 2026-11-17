<?php

namespace Tests\Feature\Api;

use App\Aodb\AodbSpec;
use App\Api\FieldSet;
use Tests\Feature\Collector\PollTestCase;

/**
 * G2 and C2: deny-by-default field groups, everywhere.
 */
class ApiScopesTest extends PollTestCase
{
    use ApiHelpers;

    private const IDENTITY_ROUTE = [
        'id', 'flow', 'flightNumber', 'fullFlightNumber', 'airlineIata', 'airlineIcao', 'status', 'milestone', 'callsign',
        'adepIata', 'adepIcao', 'adesIata', 'adesIcao', 'adepDetails', 'adesDetails', 'divertedDetails', 'codeShare', 'routeIcao',
        'hub',
    ];

    private const ALLOWED_EVENT_FIELDS = [
        null, 'id', 'flow', 'flightNumber', 'fullFlightNumber', 'airlineIata', 'airlineIcao', 'status', 'milestone', 'callsign',
        'adepIata', 'adepIcao', 'adesIata', 'adesIcao', 'routeIcao', 'hub.sameAs',
    ];

    /** Present in the sample (or added below) but outside identity + route. */
    private const OUTSIDE = ['"sobt"', '"sibt"', '"etot"', '"reg"', '"pkg"', '"paxCapa"', '"aircraftDetails"', '"dlCodes"', '"acTypeIata"', '"secretNewField"', '"9XRWT"', 'B737-800'];

    private function pollWithExtras(): void
    {
        $payload = $this->sample();
        self::record($payload, self::WB711)->secretNewField = 'never-served';
        $this->poll($payload);

        // A second poll so that /changes and history hold field changes inside and outside the groups.
        $this->travel(5)->minutes();
        $payload = $this->sample();
        self::record($payload, self::WB711)->secretNewField = 'never-served-2';
        self::record($payload, self::WB711)->status = 'TERMINATED';
        self::record($payload, self::WB711)->aibt = '2026-10-29T05:25:00Z';
        self::record($payload, self::WB711)->pkg = 'A6';
        $this->poll($payload);
    }

    private function assertNoOutsideField(string $json): void
    {
        foreach (self::OUTSIDE as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }
    }

    public function test_identity_and_route_get_exactly_those_fields_in_flights(): void
    {
        $key = $this->consumerKey(['route']);
        $this->pollWithExtras();

        $response = $this->api('flights?'.self::POLL_WINDOW, $key)->assertOk();

        $this->assertCount(51, $response->json('data'));
        foreach ($response->json('data') as $flight) {
            $this->assertSame(self::IDENTITY_ROUTE, array_keys($flight));
            $this->assertSame(['referenceTime', 'updatedAt', 'inFeed', 'sameAs'], array_keys($flight['hub']));
        }
        $this->assertNoOutsideField($response->getContent());
    }

    public function test_the_same_holds_for_one_flight_its_history_and_changes(): void
    {
        $key = $this->consumerKey(['route']);
        $this->pollWithExtras();

        $one = $this->api('flights/'.self::WB711.'?history=1', $key)->assertOk();
        $this->assertSame(self::IDENTITY_ROUTE, array_keys($one->json('data')));
        $fields = array_column($one->json('history'), 'field');
        $this->assertSame([null, 'status'], $fields, 'first_seen, then only the status change (aibt, pkg and secretNewField are outside)');
        // first_seen's value is the flight in the same shape as /flights data.
        $firstSeen = $one->json('history.0.new');
        $this->assertSame(self::IDENTITY_ROUTE, array_keys($firstSeen));
        $this->assertSame('IN_FLIGHT', $firstSeen['status']);
        $this->assertSame(['referenceTime', 'updatedAt', 'inFeed', 'sameAs'], array_keys($firstSeen['hub']));
        $this->assertNoOutsideField($one->getContent());

        $changes = $this->api('changes?cursor=0&limit=1000', $key)->assertOk();
        foreach ($changes->json('data') as $event) {
            $this->assertContains($event['field'], self::ALLOWED_EVENT_FIELDS);
        }
        $this->assertNoOutsideField($changes->getContent());
    }

    public function test_an_unknown_aodb_field_is_never_served_even_with_every_group(): void
    {
        $key = $this->consumerKey(array_keys(config('api_fields.groups')));
        $this->pollWithExtras();

        foreach (['flights?'.self::POLL_WINDOW, 'flights/'.self::WB711.'?history=1', 'changes?cursor=0&limit=1000'] as $uri) {
            $this->assertStringNotContainsString('secretNewField', $this->api($uri, $key)->assertOk()->getContent());
        }
    }

    public function test_every_documented_flight_path_is_in_a_group(): void
    {
        $everything = FieldSet::forGroups(array_keys(config('api_fields.groups')));
        $inNoGroup = array_values(array_filter(array_keys(AodbSpec::flightPaths()), fn ($path) => ! $everything->allows($path)));

        // C2: report, do not add. Batch 5 found none (146 documented paths).
        $this->assertSame([], $inNoGroup);
        $this->assertCount(146, AodbSpec::flightPaths());
    }
}
