<?php

namespace App\Collector;

/**
 * A normalised flight record: the column values of a version, and the served
 * values a correction changed (AODB path → "airport_code_corrections:<id>").
 */
final class NormalisedFlight
{
    /**
     * @param  array<string, string|int|null>  $columns
     * @param  array<string, string>  $corrections
     */
    public function __construct(
        public readonly array $columns,
        public readonly array $corrections,
    ) {}

    /**
     * Served values keyed by AODB path (the served part of first_seen).
     *
     * @return array<string, string|int|null>
     */
    public function served(): array
    {
        $served = [];
        foreach (FlightNormaliser::SERVED as $column => [, $path]) {
            $served[$path] = $this->columns[$column];
        }

        return $served;
    }
}
