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

    /**
     * @return array<string, true> dotted path → true
     */
    public static function flightPaths(): array
    {
        if (self::$flightPaths !== null) {
            return self::$flightPaths;
        }

        $document = json_decode((string) file_get_contents(resource_path(self::FILE)), true);
        $schemas = $document['components']['schemas'] ?? null;
        if (! is_array($schemas) || ! isset($schemas['FlightDto'])) {
            throw new RuntimeException('FlightDto not found in '.self::FILE);
        }

        $paths = [];
        self::walk($schemas, $schemas['FlightDto'], '', $paths, []);

        return self::$flightPaths = $paths;
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
