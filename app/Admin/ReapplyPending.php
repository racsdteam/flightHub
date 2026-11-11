<?php

namespace App\Admin;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * "Re-apply pending": set by every reference-data change, cleared by the
 * scheduled hub:reapply-corrections --if-pending (never inside a web request,
 * so a poll never waits on it for long).
 */
final class ReapplyPending
{
    public const KEY = 'hub.reapply_pending_since';

    public static function mark(): void
    {
        Cache::add(self::KEY, CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z'));
    }

    public static function isPending(): bool
    {
        return Cache::has(self::KEY);
    }

    public static function since(): ?string
    {
        return Cache::get(self::KEY);
    }

    public static function clear(): void
    {
        Cache::forget(self::KEY);
    }
}
