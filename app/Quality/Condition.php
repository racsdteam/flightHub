<?php

namespace App\Quality;

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

    /**
     * rule|field|related aodb_id: unique per flight.
     */
    public function key(): string
    {
        return $this->rule.'|'.($this->field ?? '').'|'.($this->related->aodb_id ?? '');
    }
}
