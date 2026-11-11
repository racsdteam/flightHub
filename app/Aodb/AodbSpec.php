<?php

namespace App\Aodb;

use RuntimeException;

/**
 * The documented FlightDto paths, read from the committed OpenAPI document
 * (resources/aodb/openapi-3.2.5.json). Objects are followed through $ref and
 * contribute their own path and their properties; arrays are one leaf.
 */
final class AodbSpec
{
    public const FILE = 'aodb/openapi-3.2.5.json';

    /** @var array<string, true>|null */
    private static ?array $flightPaths = null;

    /** @var array<string, array{pattern: string, items: bool}>|null */
    private static ?array $patterns = null;

    /** @var array<string, mixed>|null */
    private static ?array $schemas = null;

    /**
     * @return array<string, true> dotted path → true
     */
    public static function flightPaths(): array
    {
        if (self::$flightPaths !== null) {
            return self::$flightPaths;
        }

        $schemas = self::schemas();
        $paths = [];
        self::walk($schemas, $schemas['FlightDto'], '', $paths, []);

        return self::$flightPaths = $paths;
    }

    /**
     * Fields the spec gives a pattern for, nested objects included: dotted
     * path → [pattern, items]. items = true when the pattern applies to each
     * string of an array (codeShare.flightNumbers).
     *
     * @return array<string, array{pattern: string, items: bool}>
     */
    public static function patterns(): array
    {
        if (self::$patterns !== null) {
            return self::$patterns;
        }

        $schemas = self::schemas();
        $patterns = [];
        self::walkPatterns($schemas, $schemas['FlightDto'], '', $patterns, []);

        return self::$patterns = $patterns;
    }

    /**
     * Top-level fields FlightDto marks required.
     *
     * @return list<string>
     */
    public static function requiredFlightFields(): array
    {
        return array_values(self::schemas()['FlightDto']['required'] ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    private static function schemas(): array
    {
        if (self::$schemas !== null) {
            return self::$schemas;
        }

        $document = json_decode((string) file_get_contents(resource_path(self::FILE)), true);
        $schemas = $document['components']['schemas'] ?? null;
        if (! is_array($schemas) || ! isset($schemas['FlightDto'])) {
            throw new RuntimeException('FlightDto not found in '.self::FILE);
        }

        return self::$schemas = $schemas;
    }

    /**
     * @param  array<string, array{pattern: string, items: bool}>  $patterns
     * @param  list<string>  $seen
     */
    private static function walkPatterns(array $schemas, array $schema, string $prefix, array &$patterns, array $seen): void
    {
        foreach ($schema['properties'] ?? [] as $name => $property) {
            $path = $prefix.$name;
            if (isset($property['pattern'])) {
                $patterns[$path] = ['pattern' => $property['pattern'], 'items' => false];
            } elseif (($property['type'] ?? null) === 'array' && isset($property['items']['pattern'])) {
                $patterns[$path] = ['pattern' => $property['items']['pattern'], 'items' => true];
            }

            $ref = $property['$ref'] ?? null;
            if (is_string($ref)) {
                $target = substr($ref, strrpos($ref, '/') + 1);
                if (isset($schemas[$target]['properties']) && ! in_array($target, $seen, true)) {
                    self::walkPatterns($schemas, $schemas[$target], $path.'.', $patterns, [...$seen, $target]);
                }
            }
        }
    }

    public static function isDocumented(string $path): bool
    {
        return isset(self::flightPaths()[$path]);
    }

    /**
     * @param  array<string, array>  $schemas
     * @param  array<string, true>  $paths
     * @param  list<string>  $seen  schema names on the current branch (no cycles)
     */
    private static function walk(array $schemas, array $schema, string $prefix, array &$paths, array $seen): void
    {
        foreach ($schema['properties'] ?? [] as $name => $property) {
            $path = $prefix.$name;
            $paths[$path] = true;

            $ref = $property['$ref'] ?? null;
            if (is_string($ref)) {
                $target = substr($ref, strrpos($ref, '/') + 1);
                if (isset($schemas[$target]['properties']) && ! in_array($target, $seen, true)) {
                    self::walk($schemas, $schemas[$target], $path.'.', $paths, [...$seen, $target]);
                }
            }
        }
    }
}
