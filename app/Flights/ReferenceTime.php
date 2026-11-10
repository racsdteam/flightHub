<?php

namespace App\Flights;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * The time that places a flight in a window (D-021): scheduled, else
 * estimated, else actual. First non-null field in the flow's order wins.
 */
final class ReferenceTime
{
    public const ORDER = [
        'ARR' => ['sibt', 'eibt', 'eldt', 'aibt', 'aldt'],
        'DEP' => ['sobt', 'eobt', 'tobt', 'etot', 'aobt', 'atot'],
    ];

    private function __construct(
        public readonly DateTimeImmutable $time,
        public readonly string $source,
    ) {}

    /**
     * @param  array<string, DateTimeInterface|null>  $times  AODB time fields by name
     * @return self|null null when the flow is unknown or every field is null
     */
    public static function resolve(?string $flow, array $times): ?self
    {
        $order = self::ORDER[strtoupper(trim((string) $flow))] ?? [];

        foreach ($order as $field) {
            $value = $times[$field] ?? null;
            if ($value !== null) {
                $utc = DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'));

                return new self($utc, $field);
            }
        }

        return null;
    }
}
