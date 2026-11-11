<?php

namespace App\Admin;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Times on the admin screens: shown in Kigali time (labelled), UTC in the
 * title attribute. Stored times are UTC.
 */
final class Times
{
    public static function utc(DateTimeInterface|string|null $time): ?CarbonImmutable
    {
        if ($time === null) {
            return null;
        }

        return $time instanceof DateTimeInterface
            ? CarbonImmutable::instance($time)->utc()
            : CarbonImmutable::parse($time, 'UTC');
    }

    public static function kigali(DateTimeInterface|string|null $time, string $format = 'Y-m-d H:i'): ?string
    {
        return self::utc($time)?->setTimezone('Africa/Kigali')->format($format);
    }

    public static function age(DateTimeInterface|string|null $time): ?string
    {
        $utc = self::utc($time);
        if ($utc === null) {
            return null;
        }
        $minutes = (int) floor($utc->diffInSeconds(CarbonImmutable::now('UTC'), true) / 60);

        return match (true) {
            $minutes < 1 => 'just now',
            $minutes < 120 => "{$minutes} min ago",
            $minutes < 2880 => intdiv($minutes, 60).' h ago',
            default => intdiv($minutes, 1440).' days ago',
        };
    }
}
