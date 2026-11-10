<?php

namespace Tests\Feature\Collector;

use App\Aodb\AodbClient;
use App\Aodb\CanonicalJson;
use App\Aodb\FixtureAodbClient;
use Illuminate\Support\Facades\DB;
use stdClass;
use Tests\TestCase;

/**
 * Polls against the committed 2026-10-29 sample (D-026), served by the
 * fixture client. Time is frozen at the sample's moment, so the poll window
 * is Kigali 2026-10-28 00:00 → 2026-10-31 00:00 = [10-27 22:00Z, 10-30 22:00Z).
 */
abstract class PollTestCase extends TestCase
{
    protected const SAMPLE = 'HRYR-2026-10-29T074230Z.json';

    protected const WB711 = '8ad28dee-1345-4e86-a9b0-9a0ec98e585a';

    protected const KQ470 = '0af6b9d4-e030-40cd-aea5-108df1e6bae6';

    protected const KQ478 = '5be3afe4-320c-4004-bb74-8f7d4686620a';

    protected const KQ478D = '2e1cd799-26d8-4d29-80f2-ec6706b04649';

    protected const WB464A = 'd9d80049-0478-4abe-bf7a-8fcd3608b802';

    protected const KQ479_MORNING = '54aaacc1-3dbb-43e2-b63c-5257adb83852';

    protected const TK606_ARR_TK = 'df4405bb-8ce1-47f6-b8b5-3c3b2fa35332';

    protected const WB464_ARR = 'dac72e43-b4cb-4d09-9aa5-e0398fa5a6b8';

    protected FixtureAodbClient $aodb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-29 07:42:30');
        $this->aodb = new FixtureAodbClient(base_path('tests/Fixtures/aodb'));
        $this->app->instance(AodbClient::class, $this->aodb);
    }

    /**
     * A fresh copy of the sample payload, to build variants from.
     */
    protected function sample(): stdClass
    {
        return CanonicalJson::decode((string) file_get_contents(base_path('tests/Fixtures/aodb/'.self::SAMPLE)));
    }

    protected static function record(stdClass $payload, string $id): stdClass
    {
        foreach ($payload->flights as $record) {
            if (($record->id ?? null) === $id) {
                return $record;
            }
        }
        self::fail("Record {$id} not in payload.");
    }

    protected static function without(stdClass $payload, string $id): stdClass
    {
        $payload->flights = array_values(array_filter($payload->flights, fn ($r) => ($r->id ?? null) !== $id));

        return $payload;
    }

    /**
     * Run hub:poll; with a payload, serve it as the next answer.
     */
    protected function poll(stdClass|string|null $payload = null): object
    {
        if ($payload !== null) {
            $this->aodb->queueAirport(is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_SLASHES));
        }

        $this->assertSame(0, $this->artisan('hub:poll')->run());

        return DB::table('polls')->orderByDesc('id')->first();
    }

    protected function flight(string $aodbId): object
    {
        return DB::table('flights')->where('aodb_id', $aodbId)->first();
    }

    protected function events(string $aodbId, ?string $kind = null): array
    {
        return DB::table('change_events')
            ->join('flights', 'flights.id', '=', 'change_events.flight_id')
            ->where('flights.aodb_id', $aodbId)
            ->when($kind, fn ($q) => $q->where('change_events.kind', $kind))
            ->orderBy('change_events.id')
            ->get(['change_events.*'])
            ->all();
    }

    protected function versions(string $aodbId): array
    {
        return DB::table('flight_versions')
            ->join('flights', 'flights.id', '=', 'flight_versions.flight_id')
            ->where('flights.aodb_id', $aodbId)
            ->orderBy('flight_versions.version_no')
            ->get(['flight_versions.*'])
            ->all();
    }
}
