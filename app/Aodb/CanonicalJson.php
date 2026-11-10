<?php

namespace App\Aodb;

use JsonException;
use stdClass;

/**
 * Canonical JSON: objects with keys sorted recursively, arrays in order,
 * nothing added or removed. JSON is decoded to stdClass so that {} and []
 * stay distinct. Key order alone is therefore never a change.
 */
final class CanonicalJson
{
    private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    /**
     * @throws JsonException when the text is not JSON
     */
    public static function decode(string $json): mixed
    {
        return json_decode($json, false, 512, JSON_THROW_ON_ERROR);
    }

    public static function encode(mixed $value): string
    {
        return json_encode(self::sort($value), self::FLAGS);
    }

    public static function sha256(mixed $value): string
    {
        return hash('sha256', self::encode($value));
    }

    public static function sort(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $props = get_object_vars($value);
            ksort($props, SORT_STRING);
            $sorted = new stdClass;
            foreach ($props as $key => $item) {
                $sorted->{$key} = self::sort($item);
            }

            return $sorted;
        }

        if (is_array($value)) {
            return array_map(self::sort(...), $value);
        }

        return $value;
    }

    /**
     * Leaves of an object as dotted paths (paxDetails.pmr) → canonical JSON of
     * the value. Arrays are one leaf; an empty object has no leaves.
     *
     * @return array<string, string>
     */
    public static function leaves(stdClass $object, string $prefix = ''): array
    {
        $leaves = [];
        foreach (get_object_vars($object) as $key => $value) {
            $path = $prefix.$key;
            if ($value instanceof stdClass) {
                $leaves += self::leaves($value, $path.'.');
            } else {
                $leaves[$path] = self::encode($value);
            }
        }

        return $leaves;
    }

    /**
     * The value at a dotted path, or null when absent.
     */
    public static function get(stdClass $object, string $path): mixed
    {
        $value = $object;
        foreach (explode('.', $path) as $key) {
            if (! $value instanceof stdClass || ! property_exists($value, $key)) {
                return null;
            }
            $value = $value->{$key};
        }

        return $value;
    }
}
