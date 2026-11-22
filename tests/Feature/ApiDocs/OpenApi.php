<?php

namespace Tests\Feature\ApiDocs;

use App\Http\Controllers\ApiDocsController;
use Illuminate\Testing\TestResponse;
use Opis\JsonSchema\CompliantValidator;
use Opis\JsonSchema\Errors\ErrorFormatter;
use PHPUnit\Framework\Assert;
use Symfony\Component\Yaml\Yaml;

/**
 * The API's complete INTERNAL OpenAPI document (PublicOpenApi: the public one), and
 * validation against it with opis/json-schema (OpenAPI 3.1 schemas are JSON Schema
 * 2020-12). The whole document is registered as one resource, so every $ref resolves
 * inside it.
 */
class OpenApi
{
    public const FILE = ApiDocsController::INTERNAL_DOCUMENT;

    /** The schema resource's id (a name for opis, never fetched; .invalid never resolves). */
    public const ID = 'https://flighthub.invalid/internal/flighthub-v1.openapi.yaml';

    protected static ?object $document = null;

    /** @var array<string, mixed>|null */
    protected static ?array $tree = null;

    protected static ?CompliantValidator $validator = null;

    /**
     * The document with JSON-like types: maps as objects, lists as arrays.
     */
    public static function document(): object
    {
        return static::$document ??= Yaml::parseFile(
            base_path(static::FILE),
            Yaml::PARSE_OBJECT_FOR_MAP | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE,
        );
    }

    /**
     * The document as nested arrays, for walking it.
     *
     * @return array<string, mixed>
     */
    public static function tree(): array
    {
        return static::$tree ??= json_decode(json_encode(static::document(), JSON_THROW_ON_ERROR), true);
    }

    public static function validator(): CompliantValidator
    {
        if (static::$validator === null) {
            static::$validator = new CompliantValidator(null, 20, false);
            static::$validator->resolver()->registerRaw(static::document(), static::ID);
        }

        return static::$validator;
    }

    /**
     * Errors of a whole document against the official OpenAPI 3.1 meta-schema
     * (tests/Fixtures/openapi-3.1-schema.json; see OpenApiDocumentTest for its source and
     * why "$dynamicRef": "#meta" is read as "#/$defs/schema").
     *
     * @return array<string, mixed>
     */
    public static function metaSchemaErrors(object $document): array
    {
        $id = 'https://spec.openapis.org/oas/3.1/schema/2022-10-07';
        $raw = (string) file_get_contents(base_path('tests/Fixtures/openapi-3.1-schema.json'));
        $validator = new CompliantValidator(null, 20, false);
        $validator->resolver()->registerRaw(json_decode(str_replace('"$dynamicRef": "#meta"', '"$ref": "#/$defs/schema"', $raw), false, 512, JSON_THROW_ON_ERROR), $id);
        $result = $validator->validate($document, $id);

        return $result->isValid() ? [] : (new ErrorFormatter)->format($result->error(), true);
    }

    /**
     * A JSON pointer from its parts, escaped (~0, ~1) and percent-encoded for a URI fragment.
     */
    public static function pointer(string|int ...$parts): string
    {
        return implode('', array_map(fn ($p) => '/'.rawurlencode(str_replace(['~', '/'], ['~0', '~1'], (string) $p)), $parts));
    }

    /**
     * The node at a pointer made by pointer(), following $ref; returns [node, effective pointer].
     *
     * @return array{0: mixed, 1: string}
     */
    public static function resolve(string $pointer): array
    {
        for ($hops = 0; $hops < 10; $hops++) {
            $node = static::tree();
            foreach (array_slice(explode('/', $pointer), 1) as $part) {
                $key = str_replace(['~1', '~0'], ['/', '~'], rawurldecode($part));
                Assert::assertIsArray($node, "{$pointer}: not a container");
                Assert::assertArrayHasKey($key, $node, "{$pointer}: missing {$key}");
                $node = $node[$key];
            }
            if (! is_array($node) || ! isset($node['$ref'])) {
                return [$node, $pointer];
            }
            Assert::assertStringStartsWith('#/', $node['$ref'], 'only local $refs are used');
            $pointer = self::pointer(...array_map(
                fn ($p) => str_replace(['~1', '~0'], ['/', '~'], $p),
                array_slice(explode('/', substr($node['$ref'], 1)), 1),
            ));
        }
        Assert::fail("{$pointer}: \$ref loop");
    }

    /**
     * Validation errors of a value against the schema at a pointer ([] when valid).
     *
     * @return list<string>
     */
    public static function errors(mixed $data, string $pointer): array
    {
        $result = static::validator()->validate($data, static::ID.'#'.$pointer);
        if ($result->isValid()) {
            return [];
        }
        $lines = [];
        foreach ((new ErrorFormatter)->format($result->error(), true) as $path => $messages) {
            foreach ((array) $messages as $message) {
                $lines[] = "{$path}: {$message}";
            }
        }

        return $lines;
    }

    /**
     * A real response matches the document: its status is documented for the
     * operation, it is JSON, the declared headers are present and valid, and
     * the body validates against the schema.
     */
    public static function assertResponse(TestResponse $response, string $path, string $method = 'get'): void
    {
        $status = (string) $response->status();
        $responses = static::tree()['paths'][$path][$method]['responses'] ?? null;
        Assert::assertIsArray($responses, "{$method} {$path} is not in the document");
        Assert::assertArrayHasKey($status, $responses, "{$status} is not documented for {$method} {$path}");

        static::assertAgainst($response, self::pointer('paths', $path, $method, 'responses', $status), "{$method} {$path} {$status}");
    }

    /**
     * A real response matches the response object at a pointer (its $ref followed).
     */
    public static function assertAgainst(TestResponse $response, string $pointer, string $label): void
    {
        [$object, $pointer] = static::resolve($pointer);

        foreach (array_keys($object['headers'] ?? []) as $name) {
            [$header, $headerPointer] = static::resolve($pointer.self::pointer('headers', $name));
            $value = $response->headers->get($name);
            if ($header['required'] ?? false) {
                Assert::assertNotNull($value, "{$label}: header {$name} missing");
            }
            if ($value !== null) {
                $typed = ($header['schema']['type'] ?? null) === 'integer' && ctype_digit($value) ? (int) $value : $value;
                Assert::assertSame([], static::errors($typed, $headerPointer.'/schema'), "{$label}: header {$name}");
            }
        }

        Assert::assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'), "{$label}: content type");
        Assert::assertArrayHasKey('application/json', $object['content'] ?? [], "{$label}: no JSON content documented");
        $body = json_decode((string) $response->getContent(), false, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame([], static::errors($body, $pointer.self::pointer('content', 'application/json', 'schema')), $label);
    }
}
