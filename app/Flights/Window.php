<?php

namespace App\Flights;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * A time window [from, to) in UTC, half-open, at most 7 days (D-021).
 */
final class Window
{
    public const MAX_SECONDS = 7 * 24 * 3600;

    private function __construct(
        public readonly DateTimeImmutable $from,
        public readonly DateTimeImmutable $to,
    ) {}

    public static function between(DateTimeInterface $from, DateTimeInterface $to): self
    {
        $utc = new DateTimeZone('UTC');
        $from = DateTimeImmutable::createFromInterface($from)->setTimezone($utc);
        $to = DateTimeImmutable::createFromInterface($to)->setTimezone($utc);

        if ($to <= $from) {
            throw new InvalidArgumentException('Window end must be after its start.');
        }
        if ($to->getTimestamp() - $from->getTimestamp() > self::MAX_SECONDS) {
            throw new InvalidArgumentException('Window is longer than 7 days.');
        }

        return new self($from, $to);
    }

    /**
     * The Kigali calendar day (Africa/Kigali, UTC+2) as a UTC window.
     */
    public static function kigaliDay(string $date): self
    {
        $kigali = new DateTimeZone('Africa/Kigali');
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $kigali);

        if ($start === false || $start->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Expected a date as YYYY-MM-DD.');
        }

        return self::between($start, $start->modify('+1 day'));
    }

    public function contains(DateTimeInterface $time): bool
    {
        return $time >= $this->from && $time < $this->to;
    }
}
