<?php

namespace App\Quality;

use LengthException;

/**
 * One data-quality condition found on a flight.
 */
final class Condition
{
    /**
     * @param  string|null  $field  AODB path, for per-field rules
     * @param  object|null  $related  the other flights row, for duplicates
     * @param  array<string, mixed>  $details  the values that triggered it
     */
    public function __construct(
        public readonly string $rule,
        public readonly ?string $field,
        public readonly ?object $related,
        public readonly array $details,
    ) {}

    public const KEY_MAX = 160;

    /**
     * rule|field|related flights.id: unique per flight. Internal only; flag_key
     * is never exposed.
     */
    public function key(): string
    {
        return self::keyOf($this->rule, $this->field, $this->related?->id);
    }

    public static function keyOf(string $rule, ?string $field, int|string|null $relatedFlightId): string
    {
        $key = $rule.'|'.($field ?? '').'|'.($relatedFlightId ?? '');
        if (strlen($key) > self::KEY_MAX) {
            throw new LengthException("Quality flag key for rule {$rule} is longer than ".self::KEY_MAX.' characters.');
        }

        return $key;
    }
}
