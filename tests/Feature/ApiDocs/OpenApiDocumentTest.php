<?php

namespace Tests\Feature\ApiDocs;

use App\Collector\FlightNormaliser;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;
use Throwable;

/**
 * D1, D3, D5: the OpenAPI document is a valid OpenAPI 3.1 document, matches the
 * routes and the field groups; the Claude Code deny rules exist.
 *
 * "Valid" is checked here structurally (fields, status codes, path templates,
 * $refs, security, tags, operation ids) and by compiling every schema as JSON
 * Schema 2020-12 and validating every example against its schema. The official
 * OpenAPI meta-schema is not used (it would be one more downloaded file).
 */
class OpenApiDocumentTest extends TestCase
{
    private const OPERATIONS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    public function test_the_document_is_openapi_3_1_with_its_info_servers_and_bearer_key(): void
    {
        $doc = OpenApi::tree();

        $this->assertMatchesRegularExpression('/^3\.1\.\d+$/', $doc['openapi']);
        $this->assertSame(['RAC Flight Data Hub API', '1.0.0'], [$doc['info']['title'], $doc['info']['version']]);
        $this->assertSame(
            ['https://flightdata.rac.co.rw/api/v1', 'http://127.0.0.1:8000/api/v1'],
            array_column($doc['servers'], 'url'),
        );
        $this->assertSame(['type' => 'http', 'scheme' => 'bearer'], array_intersect_key($doc['components']['securitySchemes']['apiKey'], ['type' => 1, 'scheme' => 1]));
        $this->assertStringStartsWith('fdh_', $doc['components']['securitySchemes']['apiKey']['bearerFormat']);
        $this->assertSame([['apiKey' => []]], $doc['security']);
        foreach (['Keys and the Authorization header', 'Field groups', 'Times and days', 'meta.asOf', 'as_of', 'Changes since', 'Duplicates and removed flights', 'source', 'Rate limits'] as $section) {
            $this->assertStringContainsString($section, $doc['info']['description'], "introduction: {$section}");
        }
    }

    public function test_the_document_is_structurally_valid(): void
    {
        $doc = OpenApi::tree();
        $problems = [];

        $allowed = ['openapi', 'info', 'jsonSchemaDialect', 'servers', 'paths', 'webhooks', 'components', 'security', 'tags', 'externalDocs'];
        foreach (array_keys($doc) as $key) {
            if (! in_array($key, $allowed, true) && ! str_starts_with($key, 'x-')) {
                $problems[] = "unknown top-level field {$key}";
            }
        }

        $schemes = array_keys($doc['components']['securitySchemes'] ?? []);
        $tags = array_column($doc['tags'] ?? [], 'name');
        $operationIds = [];

        foreach ($doc['paths'] as $path => $item) {
            if (! str_starts_with($path, '/')) {
                $problems[] = "path {$path} does not start with /";
            }
            preg_match_all('/\{([^}]+)\}/', $path, $m);
            $templated = $m[1];

            foreach ($item as $method => $operation) {
                if (! in_array($method, self::OPERATIONS, true)) {
                    if (! in_array($method, ['summary', 'description', 'servers', 'parameters'], true) && ! str_starts_with($method, 'x-')) {
                        $problems[] = "{$path}: unknown field {$method}";
                    }

                    continue;
                }
                $at = "{$method} {$path}";
                if (! is_string($operation['operationId'] ?? null)) {
                    $problems[] = "{$at}: no operationId";
                } else {
                    $operationIds[] = $operation['operationId'];
                }

                foreach ($operation['tags'] ?? [] as $tag) {
                    if (! in_array($tag, $tags, true)) {
                        $problems[] = "{$at}: undeclared tag {$tag}";
                    }
                }
                foreach ($operation['security'] ?? $doc['security'] as $requirement) {
                    foreach (array_keys($requirement) as $scheme) {
                        if (! in_array($scheme, $schemes, true)) {
                            $problems[] = "{$at}: unknown security scheme {$scheme}";
                        }
                    }
                }

                $pathParameters = [];
                foreach ($operation['parameters'] ?? [] as $i => $parameter) {
                    [$parameter] = OpenApi::resolve(OpenApi::pointer('paths', $path, $method, 'parameters', $i));
                    if (! isset($parameter['name'], $parameter['in'], $parameter['schema']) || ! in_array($parameter['in'], ['query', 'header', 'path', 'cookie'], true)) {
                        $problems[] = "{$at}: parameter {$i} lacks name, a valid in, or schema";

                        continue;
                    }
                    if ($parameter['in'] === 'path') {
                        $pathParameters[] = $parameter['name'];
                        if (($parameter['required'] ?? false) !== true) {
                            $problems[] = "{$at}: path parameter {$parameter['name']} not required";
                        }
                    }
                }
                sort($pathParameters);
                $expected = $templated;
                sort($expected);
                if ($pathParameters !== $expected) {
                    $problems[] = "{$at}: path parameters ".json_encode($pathParameters).' do not match the template';
                }

                if (($operation['responses'] ?? []) === []) {
                    $problems[] = "{$at}: no responses";
                }
                foreach (array_keys($operation['responses'] ?? []) as $status) {
                    if (! preg_match('/^([1-5]\d\d|[1-5]XX|default)$/', (string) $status)) {
                        $problems[] = "{$at}: invalid status {$status}";
                    }
                    [$response] = OpenApi::resolve(OpenApi::pointer('paths', $path, $method, 'responses', $status));
                    if (! is_string($response['description'] ?? null)) {
                        $problems[] = "{$at} {$status}: no description";
                    }
                }
            }
        }

        if (count($operationIds) !== count(array_unique($operationIds))) {
            $problems[] = 'operationIds are not unique';
        }

        // Every $ref resolves to a node of the document.
        array_walk_recursive($doc, function ($value, $key) use (&$problems) {
            if ($key === '$ref') {
                try {
                    OpenApi::resolve(OpenApi::pointer(...array_map(
                        fn ($p) => str_replace(['~1', '~0'], ['/', '~'], $p),
                        array_slice(explode('/', substr($value, 1)), 1),
                    )));
                } catch (Throwable $e) {
                    $problems[] = "\$ref {$value} does not resolve";
                }
            }
        });

        $this->assertSame([], $problems);
    }

    public function test_every_schema_compiles_and_every_example_matches_its_schema(): void
    {
        $doc = OpenApi::tree();
        $problems = [];

        foreach (array_keys($doc['components']['schemas']) as $name) {
            try {
                OpenApi::errors(null, OpenApi::pointer('components', 'schemas', $name));
            } catch (Throwable $e) {
                $problems[] = "schema {$name}: ".$e->getMessage();
            }
        }

        $examples = 0;
        $check = function (array $mediaTypes, string $base) use (&$problems, &$examples) {
            foreach ($mediaTypes as $type => $media) {
                foreach ($media['examples'] ?? [] as $name => $example) {
                    $examples++;
                    $value = json_decode(json_encode($example['value']));
                    foreach (OpenApi::errors($value, $base.OpenApi::pointer('content', $type, 'schema')) as $error) {
                        $problems[] = "example {$name} at {$base}: {$error}";
                    }
                }
            }
        };
        foreach ($doc['paths'] as $path => $item) {
            foreach ($item as $method => $operation) {
                foreach (array_keys($operation['responses'] ?? []) as $status) {
                    [$response, $pointer] = OpenApi::resolve(OpenApi::pointer('paths', $path, $method, 'responses', $status));
                    $check($response['content'] ?? [], $pointer);
                }
            }
        }
        foreach ($doc['components']['responses'] as $name => $response) {
            $check($response['content'] ?? [], OpenApi::pointer('components', 'responses', $name));
        }
        foreach ($doc['components']['parameters'] as $name => $parameter) {
            if (array_key_exists('example', $parameter)) {
                $examples++;
                foreach (OpenApi::errors($parameter['example'], OpenApi::pointer('components', 'parameters', $name, 'schema')) as $error) {
                    $problems[] = "parameter {$name} example: {$error}";
                }
            }
        }

        $this->assertSame([], $problems);
        $this->assertGreaterThanOrEqual(20, $examples, 'the examples were checked');
    }

    public function test_the_validator_rejects_what_the_document_forbids(): void
    {
        $flight = fn (array $changes = []) => json_decode(json_encode($changes + [
            'id' => 'x', 'flow' => 'ARR', 'flightNumber' => '470', 'fullFlightNumber' => 'KQ470', 'airlineIata' => 'KQ',
            'airlineIcao' => 'KQA', 'status' => 'SCHEDULED', 'milestone' => null, 'callsign' => null,
            'hub' => ['referenceTime' => '2026-10-29T06:00:00Z', 'inFeed' => true, 'sameAs' => null],
        ]));
        $schema = OpenApi::pointer('components', 'schemas', 'Flight');

        $this->assertSame([], OpenApi::errors($flight(), $schema));
        $this->assertNotSame([], OpenApi::errors($flight(['internalId' => 7]), $schema), 'a field in no group');
        $this->assertNotSame([], OpenApi::errors($flight(['sobt' => 5]), $schema), 'a time that is not a string');
        $this->assertNotSame([], OpenApi::errors($flight(['flow' => 'XXX']), $schema), 'flow outside ARR/DEP');
        $this->assertNotSame([], OpenApi::errors($flight(['adepDetails' => ['adepName' => 3]]), $schema), 'a nested field of the wrong type');
        $this->assertNotSame([], OpenApi::errors(json_decode('{"error":"UNAUTHORIZED"}'), OpenApi::pointer('components', 'schemas', 'Error')), 'no message');
        $this->assertNotSame([], OpenApi::errors(json_decode('{"data":[],"meta":{"asOf":null,"feed":"ok","window":{"from":"2026-10-28T22:00:00Z","to":"2026-10-29T22:00:00Z"},"requestedAsOf":null,"extra":1}}'), OpenApi::pointer('components', 'schemas', 'FlightList')), 'an unknown meta member');
    }

    public function test_every_api_route_is_documented_and_every_documented_path_exists(): void
    {
        $routes = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();
            // The fallback (404) and the any-other-method catch-all (405) are documented as responses.
            if (! str_starts_with($uri, 'api/v1/') || $route->isFallback || str_contains($uri, '{any?}')) {
                continue;
            }
            $this->assertSame(['GET', 'HEAD'], $route->methods(), $uri);
            $routes[] = 'get '.substr($uri, strlen('api/v1'));
        }

        $documented = [];
        foreach (OpenApi::tree()['paths'] as $path => $item) {
            foreach (array_intersect(array_keys($item), self::OPERATIONS) as $method) {
                $documented[] = "{$method} {$path}";
            }
        }

        sort($routes);
        sort($documented);
        $this->assertSame(['get /changes', 'get /flights', 'get /flights/{aodbId}', 'get /health'], $routes);
        $this->assertSame($routes, $documented);
    }

    public function test_every_aodb_flight_field_the_hub_serves_is_in_the_flight_schema_with_its_group(): void
    {
        $aodb = json_decode((string) file_get_contents(base_path('resources/aodb/openapi-3.2.5.json')), true);
        $dtos = $aodb['components']['schemas'];
        $schemas = OpenApi::tree()['components']['schemas'];
        $flight = $schemas['Flight']['properties'];

        $groupOf = [];
        foreach (config('api_fields.groups') as $group => $paths) {
            foreach ($paths as $path) {
                $field = preg_replace('/\.\*$/', '', $path);
                $this->assertArrayNotHasKey($field, $groupOf, "{$field} is in two groups");
                $groupOf[$field] = $group;
            }
        }

        $served = 0;
        foreach ($dtos['FlightDto']['properties'] as $name => $property) {
            if (! isset($groupOf[$name])) {
                $this->assertArrayNotHasKey($name, $flight, "{$name} is in no group, so it is not in the Flight schema");

                continue;
            }
            $served++;
            $this->assertArrayHasKey($name, $flight, "{$name} is served but not in the Flight schema");
            $this->assertSame($groupOf[$name], $flight[$name]['x-field-group'] ?? null, "{$name}: x-field-group");
            $this->assertNotSame('', trim($flight[$name]['description'] ?? ''), "{$name}: description");

            // A whole object: its AODB fields are in the hub's copy of the object schema
            // (ghrOperatorDetails without the contact fields removed at ingest, D-035).
            if (isset($property['$ref']) && ($dtos[basename($property['$ref'])]['type'] ?? null) === 'object') {
                $dto = basename($property['$ref']);
                $this->assertSame(['$ref' => '#/components/schemas/'.$dto], $flight[$name]['anyOf'][0] ?? null, "{$name}: object schema");
                $expected = array_keys($dtos[$dto]['properties']);
                if ($name === 'ghrOperatorDetails') {
                    $expected = array_values(array_diff($expected, FlightNormaliser::STRIPPED_GHR_FIELDS));
                }
                $this->assertSame($expected, array_keys($schemas[$dto]['properties']), "{$dto}: fields");
            }
        }
        $this->assertSame(81, count($dtos['FlightDto']['properties']), 'AODB spec 3.2.5');
        $this->assertSame($served, count($flight) - 1, 'the Flight schema holds the served AODB fields and hub, nothing else');

        foreach ($schemas['Hub']['properties'] as $name => $property) {
            $this->assertSame($groupOf['hub.'.$name] ?? null, $property['x-field-group'] ?? null, "hub.{$name}: x-field-group");
        }
        $hubFields = array_map(fn ($f) => substr($f, 4), array_filter(array_keys($groupOf), fn ($f) => str_starts_with($f, 'hub.')));
        $this->assertEqualsCanonicalizing($hubFields, array_keys($schemas['Hub']['properties']));

        // identity is always given: its fields are required, the others are not.
        $identity = array_values(array_filter(config('api_fields.groups.identity'), fn ($f) => ! str_starts_with($f, 'hub.')));
        $this->assertSame([...$identity, 'hub'], $schemas['Flight']['required']);
    }

    public function test_claude_code_may_not_read_or_edit_the_env_files(): void
    {
        $settings = json_decode((string) file_get_contents(base_path('.claude/settings.json')), true, 512, JSON_THROW_ON_ERROR);

        $deny = $settings['permissions']['deny'] ?? [];
        foreach (['.env', '.env.testing', '.env.local', '.env.production'] as $file) {
            foreach (['Read', 'Edit'] as $tool) {
                $this->assertContains("{$tool}(./{$file})", $deny);
            }
        }
        $this->assertSame([], array_values(array_filter($deny, fn ($rule) => str_contains($rule, '.env.example'))), '.env.example stays readable');
        $this->assertSame(['commit' => '', 'pr' => ''], $settings['attribution'], 'attribution stays off');
    }
}
