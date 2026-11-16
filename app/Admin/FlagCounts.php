<?php

namespace App\Admin;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Read-only counts of quality flags for the screens' trends (D-052), from
 * first_detected_at and cleared_at only. "Open at T" counts a flag detected by
 * T and not cleared by T; a flag cleared and re-opened since T counts by its
 * latest state (cleared_at is reset when a flag re-opens).
 */
final class FlagCounts
{
    public static function openAt(CarbonImmutable $at): int
    {
        $t = $at->utc()->format('Y-m-d H:i:s.u');

        return DB::table('quality_flags')
            ->where('first_detected_at', '<=', $t)
            ->where(fn ($q) => $q->where('state', 'open')->orWhere('cleared_at', '>', $t))
            ->count();
    }

    public static function clearedBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return DB::table('quality_flags')->where('state', 'cleared')
            ->where('cleared_at', '>=', $from->utc()->format('Y-m-d H:i:s.u'))
            ->where('cleared_at', '<', $to->utc()->format('Y-m-d H:i:s.u'))
            ->count();
    }
}
