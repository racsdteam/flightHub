<?php

namespace Tests\Unit;

use App\Flights\ReferenceTime;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class ReferenceTimeTest extends TestCase
{
    private static function t(string $iso): DateTimeImmutable
    {
        return new DateTimeImmutable($iso);
    }

    public function test_wb464a_dep_without_sobt_uses_etot(): void
    {
        $ref = ReferenceTime::resolve('DEP', ['sobt' => null, 'etot' => self::t('2026-10-29T00:45:00Z')]);

        $this->assertSame('etot', $ref->source);
        $this->assertSame('2026-10-29T00:45:00+00:00', $ref->time->format(DATE_ATOM));
    }

    public function test_kq478d_arr_without_sibt_uses_eibt(): void
    {
        $ref = ReferenceTime::resolve('ARR', [
            'sibt' => null,
            'eibt' => self::t('2026-10-29T05:09:00Z'),
            'aldt' => self::t('2026-10-29T05:01:00Z'),
        ]);

        $this->assertSame('eibt', $ref->source);
        $this->assertSame('2026-10-29T05:09:00+00:00', $ref->time->format(DATE_ATOM));
    }

    public function test_morning_kq479_dep_uses_etot(): void
    {
        $ref = ReferenceTime::resolve('DEP', ['etot' => self::t('2026-10-29T05:52:00Z')]);

        $this->assertSame('etot', $ref->source);
        $this->assertSame('2026-10-29T05:52:00+00:00', $ref->time->format(DATE_ATOM));
    }

    public function test_wb452_dep_sobt_wins_over_aobt(): void
    {
        $ref = ReferenceTime::resolve('DEP', [
            'sobt' => self::t('2026-10-29T07:40:00Z'),
            'aobt' => self::t('2026-10-29T05:55:00Z'),
        ]);

        $this->assertSame('sobt', $ref->source);
        $this->assertSame('2026-10-29T07:40:00+00:00', $ref->time->format(DATE_ATOM));
    }

    public function test_dep_order_is_sobt_eobt_tobt_etot_aobt_atot(): void
    {
        $all = [
            'sobt' => self::t('2026-10-29T07:00:00Z'),
            'eobt' => self::t('2026-10-29T07:10:00Z'),
            'tobt' => self::t('2026-10-29T07:20:00Z'),
            'etot' => self::t('2026-10-29T07:30:00Z'),
            'aobt' => self::t('2026-10-29T07:40:00Z'),
            'atot' => self::t('2026-10-29T07:50:00Z'),
        ];

        foreach (['sobt', 'eobt', 'tobt', 'etot', 'aobt', 'atot'] as $expected) {
            $this->assertSame($expected, ReferenceTime::resolve('DEP', $all)->source);
            $all[$expected] = null;
        }
        $this->assertNull(ReferenceTime::resolve('DEP', $all));
    }

    public function test_dep_eobt_wins_over_etot_when_no_sobt(): void
    {
        $ref = ReferenceTime::resolve('DEP', [
            'eobt' => self::t('2026-10-29T08:05:00Z'),
            'etot' => self::t('2026-10-29T08:15:00Z'),
        ]);

        $this->assertSame('eobt', $ref->source);
        $this->assertSame('2026-10-29T08:05:00+00:00', $ref->time->format(DATE_ATOM));
    }

    public function test_arr_with_only_aldt(): void
    {
        $ref = ReferenceTime::resolve('ARR', ['aldt' => self::t('2026-10-29T11:20:00Z')]);

        $this->assertSame('aldt', $ref->source);
        $this->assertSame('2026-10-29T11:20:00+00:00', $ref->time->format(DATE_ATOM));
    }

    public function test_flight_with_no_times_is_null(): void
    {
        $this->assertNull(ReferenceTime::resolve('ARR', []));
        $this->assertNull(ReferenceTime::resolve('DEP', ['sobt' => null, 'eobt' => null, 'tobt' => null, 'etot' => null, 'aobt' => null, 'atot' => null]));
    }

    public function test_times_are_returned_in_utc(): void
    {
        $ref = ReferenceTime::resolve('DEP', ['sobt' => self::t('2026-10-29T09:40:00+02:00')]);

        $this->assertSame('2026-10-29T07:40:00+00:00', $ref->time->format(DATE_ATOM));
    }
}
