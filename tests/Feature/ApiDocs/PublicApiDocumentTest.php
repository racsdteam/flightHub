<?php

namespace Tests\Feature\ApiDocs;

use App\Http\Controllers\ApiDocsController;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Yaml\Yaml;
use Tests\Feature\Api\ApiHelpers;
use Tests\Feature\Collector\PollTestCase;

/**
 * D-054: the public API document, the only one served. Short, plain, for outsiders:
 * GET /flights and GET /flights/{id} only, no internal words, one server (the hub's own),
 * and real responses validate against it.
 */
class PublicApiDocumentTest extends PollTestCase
{
    use ApiHelpers;

    private const FORBIDDEN_ANYWHERE = ['as_of', 'include_removed', '/changes', '/health', 'hub.quality', 'hub.corrections', '127.0.0.1', 'localhost', 'x-field-group'];

    /** Words never used in a description, summary or title. */
    private const FORBIDDEN_WORDS = ['/\bAODB\b/i', "/Innov'?ATM/i", '/\bD-0\d\d\b/', '/x-field-group/i', '/\bdeny\b/i', '/\brules?\b/i',
        '/\bfield groups?\b/i', '/\bacdm\b/i', '/ground_handling/', '/\bquality\b/i', '/\bcorrections?\b/i', '/\bpolls?\b/i',
        '/\b(change_events|flight_versions|quality_flags|api_calls|consumers)\b/', '/\bFlightPresenter|FieldSet|ApiGate\b/'];

    private function file(): string
    {
        return (string) file_get_contents(base_path(ApiDocsController::DOCUMENT));
    }

    /**
     * @return list<string> every description, summary and title in the document
     */
    private function texts(mixed $node): array
    {
        $out = [];
        foreach (is_array($node) ? $node : [] as $key => $value) {
            if (in_array($key, ['description', 'summary', 'title'], true) && is_string($value)) {
                $out[] = $value;
            }
            array_push($out, ...$this->texts($value));
        }

        return $out;
    }

    public function test_the_file_and_the_served_document_pass_the_openapi_3_1_meta_schema(): void
    {
        $this->assertSame([], OpenApi::metaSchemaErrors(PublicOpenApi::document()));
        $served = Yaml::parse($this->get('/api/openapi.yaml')->assertOk()->getContent(), Yaml::PARSE_OBJECT_FOR_MAP);
        $this->assertSame([], OpenApi::metaSchemaErrors($served));
    }

    public function test_it_documents_only_get_flights_and_get_flight_by_id(): void
    {
        $paths = PublicOpenApi::tree()['paths'];
        $this->assertSame(['/flights', '/flights/{id}'], array_keys($paths));
        $this->assertSame(['get'], array_keys($paths['/flights']));
        $this->assertSame(['get'], array_keys($paths['/flights/{id}']));
        $this->assertSame(['200', '401', '403', '422', '429'], array_map('strval', array_keys($paths['/flights']['get']['responses'])));
        $this->assertSame(['200', '401', '403', '404', '422', '429'], array_map('strval', array_keys($paths['/flights/{id}']['get']['responses'])));
        $this->assertSame(['day', 'from', 'to', 'flow', 'id', 'history'], array_keys(PublicOpenApi::tree()['components']['parameters']));
        $this->assertArrayNotHasKey('servers', PublicOpenApi::tree(), 'the server is added when served');
        $this->assertSame(['referenceTime', 'updatedAt', 'inFeed', 'sameAs', 'rotationNext', 'rotationPrev', 'rotationSource', 'minTurnaroundMinutes'],
            array_keys(PublicOpenApi::tree()['components']['schemas']['Hub']['properties']));
        $this->assertSame(['cursor', 'observedAt', 'kind', 'field', 'old', 'new', 'source'],
            array_keys(PublicOpenApi::tree()['components']['schemas']['HistoryEvent']['properties']));
    }

    public function test_every_field_the_api_can_send_is_documented_in_one_short_line(): void
    {
        $public = PublicOpenApi::tree()['components']['schemas']['Flight']['properties'];
        $internal = OpenApi::tree()['components']['schemas']['Flight']['properties'];
        $this->assertSame(array_keys($internal), array_keys($public));
        foreach ($public as $field => $schema) {
            if ($field === 'hub') {
                continue;
            }
            $this->assertNotSame('', $schema['description'] ?? '', $field);
            $this->assertLessThanOrEqual(10, self::words($schema['description']), "{$field}: one short line");
        }
    }

    /**
     * Words of a description line, not counting listed values: UPPER_CASE codes (SCHEDULED,
     * IN_FLIGHT, PAX…) and field names in backticks.
     */
    private static function words(string $line): int
    {
        $line = (string) preg_replace('/`[^`]*`/', ' ', $line);
        $tokens = array_filter(preg_split('/\s+/', trim($line)), fn ($t) => preg_match('/[A-Za-z0-9]/', $t) === 1);

        return count(array_filter($tokens, fn ($t) => preg_match('/^\(?[A-Z][A-Z0-9_]+\)?[.,;:]?$/', $t) !== 1));
    }

    public function test_the_word_count_skips_listed_values_only(): void
    {
        $this->assertSame(3, self::words('Progress step. Arrivals: SCHEDULED, AIRBORNE, FIR_ENTRY, IN_BLOCK.'));
        $this->assertSame(5, self::words('`divertedAdesIata` = airport landed at; `filedAdesIata` = planned destination.'));
        $this->assertSame(7, self::words('Aircraft registration (tail), e.g. 9XRWT — no hyphen.'));
    }

    public function test_it_is_short_plain_and_has_no_internal_words(): void
    {
        $file = $this->file();
        $this->assertLessThan(400, substr_count($file, "\n"));
        foreach (self::FORBIDDEN_ANYWHERE as $text) {
            $this->assertStringNotContainsString($text, $file);
        }
        $this->assertDoesNotMatchRegularExpression('#https?://#', $file, 'no address in the file');

        $info = PublicOpenApi::tree()['info'];
        $this->assertSame('RAC Flight Data Hub API', $info['title']);
        $this->assertLessThanOrEqual(3, count(explode("\n", trim($info['description']))));
        $this->assertStringContainsString('Authorization: Bearer <key>', $info['description']);
        $this->assertStringContainsString('You receive only the fields your key allows.', $info['description']);

        foreach ($this->texts(PublicOpenApi::tree()) as $text) {
            foreach (self::FORBIDDEN_WORDS as $word) {
                $this->assertDoesNotMatchRegularExpression($word, $text, "\"{$text}\"");
            }
        }
        // No parameter has an example (Swagger UI would pre-fill it in Try it out).
        foreach (PublicOpenApi::tree()['components']['parameters'] as $name => $parameter) {
            $this->assertArrayNotHasKey('example', $parameter, $name);
            $this->assertArrayNotHasKey('examples', $parameter, $name);
        }
    }

    public function test_the_served_document_has_one_server_the_configured_app_url(): void
    {
        config(['app.url' => 'https://hub.example.test/']);
        $served = Yaml::parse($this->get('/api/openapi.yaml')->assertOk()->getContent());

        $this->assertSame([['url' => 'https://hub.example.test/api/v1']], $served['servers']);
        $this->assertArrayNotHasKey('servers', Yaml::parse($this->file()), 'no address in the file (D-061)');

        // Under the base path (D-061): APP_URL's path is part of the server.
        config(['app.url' => 'https://systems.rac.co.rw/flighthub']);
        $this->assertSame([['url' => 'https://systems.rac.co.rw/flighthub/api/v1']], Yaml::parse(ApiDocsController::served())['servers']);
        unset($served['servers']);
        $this->assertSame(Yaml::parse($this->file()), $served, 'otherwise exactly the committed file');
    }

    public function test_its_examples_validate(): void
    {
        foreach (['/flights', '/flights/{id}'] as $path) {
            $content = PublicOpenApi::tree()['paths'][$path]['get']['responses']['200']['content']['application/json'];
            foreach ($content['examples'] as $name => $example) {
                $data = json_decode(json_encode($example['value']), false);
                $this->assertSame([], PublicOpenApi::errors($data, PublicOpenApi::pointer('paths', $path, 'get', 'responses', '200', 'content', 'application/json', 'schema')), "{$path} {$name}");
            }
        }
    }

    public function test_real_responses_validate_against_the_public_document(): void
    {
        $this->poll();
        $groups = array_values(array_diff(array_keys(config('api_fields.groups')), ['quality']));
        $every = $this->consumerKey($groups, 5000);
        $identity = $this->consumerKey([], 5000);
        // The key of the WB464 examples (Batch 10b): route, schedule, times, aircraft, resources, rotation.
        $example = $this->consumerKey(['route', 'schedule', 'times', 'aircraft', 'resources', 'rotation'], 5000);

        foreach ([$every, $identity, $example] as $key) {
            foreach (['day=2026-10-29', 'day=2026-10-29&flow=DEP', 'from=2026-10-29T05:00:00Z&to=2026-10-30T05:00:00Z'] as $query) {
                PublicOpenApi::assertResponse($this->api('flights?'.$query, $key)->assertOk(), '/flights');
            }
            foreach (DB::table('flights')->pluck('aodb_id') as $id) {
                PublicOpenApi::assertResponse($this->api("flights/{$id}?history=1", $key)->assertOk(), '/flights/{id}');
            }
        }
        PublicOpenApi::assertResponse($this->api('flights?day=2026-10-29', null)->assertStatus(401), '/flights');
        PublicOpenApi::assertResponse($this->api('flights?day=29-10-2026', $every)->assertStatus(422), '/flights');
        PublicOpenApi::assertResponse($this->api('flights/0af6b9d4-0000-0000-0000-000000000000', $every)->assertNotFound(), '/flights/{id}');
        PublicOpenApi::assertResponse($this->api('flights/'.self::KQ470.'?history=yes', $every)->assertStatus(422), '/flights/{id}');

        $limited = $this->consumerKey([], 1);
        $this->api('flights?day=2026-10-29', $limited)->assertOk();
        PublicOpenApi::assertResponse($this->api('flights?day=2026-10-29', $limited)->assertStatus(429), '/flights');
    }
}
