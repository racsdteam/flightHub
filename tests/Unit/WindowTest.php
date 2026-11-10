<?php

namespace Tests\Unit;

use App\Flights\Window;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class WindowTest extends TestCase
{
    public function test_kigali_day_is_2200z_to_2200z(): void
    {
        $w = Window::kigaliDay('2026-10-29');

        $this->assertSame('2026-10-28T22:00:00+00:00', $w->from->format(DATE_ATOM));
        $this->assertSame('2026-10-29T22:00:00+00:00', $w->to->format(DATE_ATOM));
    }

    public function test_window_is_half_open(): void
    {
        $w = Window::kigaliDay('2026-10-29');

        $this->assertTrue($w->contains(new DateTimeImmutable('2026-10-28T22:00:00Z')));
        $this->assertTrue($w->contains(new DateTimeImmutable('2026-10-29T21:59:59Z')));
        $this->assertFalse($w->contains(new DateTimeImmutable('2026-10-29T22:00:00Z')));
        $this->assertFalse($w->contains(new DateTimeImmutable('2026-10-28T21:59:59Z')));
    }

    public function test_kia_pulse_operational_day_0700_to_0700_kigali(): void
    {
        $w = Window::between(
            new DateTimeImmutable('2026-10-29T07:00:00+02:00'),
            new DateTimeImmutable('2026-10-30T07:00:00+02:00'),
        );

        $this->assertSame('2026-10-29T05:00:00+00:00', $w->from->format(DATE_ATOM));
        $this->assertSame('2026-10-30T05:00:00+00:00', $w->to->format(DATE_ATOM));
    }

    public function test_seven_days_is_allowed(): void
    {
        $w = Window::between(new DateTimeImmutable('2026-10-01T00:00:00Z'), new DateTimeImmutable('2026-10-08T00:00:00Z'));

        $this->assertSame('2026-10-08T00:00:00+00:00', $w->to->format(DATE_ATOM));
    }

    public function test_longer_than_seven_days_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Window::between(new DateTimeImmutable('2026-10-01T00:00:00Z'), new DateTimeImmutable('2026-10-08T00:00:01Z'));
    }

    public function test_empty_or_reversed_window_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Window::between(new DateTimeImmutable('2026-10-01T00:00:00Z'), new DateTimeImmutable('2026-10-01T00:00:00Z'));
    }

    public function test_invalid_date_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Window::kigaliDay('2026-02-30');
    }
}
