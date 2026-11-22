<?php

namespace App\Admin;

use Carbon\CarbonImmutable;

/**
 * The newest backup (scripts/backup.sh) seen from its manifest's NAME,
 * flighthub-<YYYYmmddTHHMMSSZ>.manifest: never a dump, never a manifest's contents. Used by
 * hub:check-feed (backup alert, D-058) and hub:preflight.
 */
final class BackupStatus
{
    public static function enabled(): bool
    {
        return trim((string) config('backup.dir')) !== '';
    }

    /**
     * The newest manifest's time (UTC), or null when there is none (or no folder).
     */
    public static function newest(): ?CarbonImmutable
    {
        $dir = rtrim(trim((string) config('backup.dir')), '/');
        $newest = null;
        foreach (glob($dir.'/flighthub-*.manifest') ?: [] as $path) {
            if (preg_match('/^flighthub-(\d{8}T\d{6}Z)\.manifest$/', basename($path), $m) !== 1) {
                continue;
            }
            $at = CarbonImmutable::createFromFormat('Ymd\THis\Z', $m[1], 'UTC');
            if ($at !== false && ($newest === null || $at->gt($newest))) {
                $newest = $at;
            }
        }

        return $newest;
    }

    /**
     * null when the check is off (no folder configured); true when there is no manifest or the
     * newest is older than config backup.stale_after_hours.
     */
    public static function stale(): ?bool
    {
        if (! self::enabled()) {
            return null;
        }
        $newest = self::newest();

        return $newest === null || $newest->lt(CarbonImmutable::now('UTC')->subHours((int) config('backup.stale_after_hours', 26)));
    }
}
