<?php

namespace App\Admin;

use App\Aodb\AodbSpec;
use Cron\CronExpression;
use DateTimeInterface;

/**
 * Display-only helpers for the admin screens (D-052): words and tones for
 * AODB codes and poll outcomes, durations, expected poll counts. No logic of
 * the hub depends on them.
 */
final class Display
{
    /** AODB flight status (FlightStatusDto) → words. */
    public const STATUS = [
        'SCHEDULED' => 'Scheduled',
        'IN_FLIGHT' => 'In flight',
        'TERMINATED' => 'Completed',
        'CANCELLED' => 'Cancelled',
        'SUSPENDED' => 'Suspended',
        'NONE' => 'None',
    ];

    /** AODB milestone (FlightMilestoneDto) → words. */
    public const MILESTONE = [
        'SCHEDULED' => 'Scheduled',
        'AIRBORNE' => 'Airborne',
        'FIR_ENTRY' => 'FIR entry',
        'FINAL' => 'Final approach',
        'LANDED' => 'Landed',
        'IN_BLOCK' => 'In block',
        'NO_IN_BLOCK' => 'Inbound not in block',
        'ON_BLOCK' => 'Inbound on block',
        'START_BOARDING' => 'Boarding started',
        'AC_READY' => 'Aircraft ready',
        'OK_START' => 'Cleared to start',
        'OFF_BLOCK' => 'Off block',
        'TAKE_OFF' => 'Take-off',
    ];

    /** Quality rule → icon (the rule name is always shown beside it). */
    public const RULE_ICONS = [
        'duplicate_suspected' => 'duplicate', 'required_missing' => 'warning', 'format_invalid' => 'x-circle',
        'value_unfit' => 'alert', 'suffixed_flight_number' => 'tag', 'actual_early' => 'clock',
        'no_actual_after_schedule' => 'clock', 'in_flight_stale' => 'plane', 'type_missing' => 'tag',
        'capacity_missing' => 'users', 'no_times' => 'clock', 'stand_unknown' => 'layers',
    ];

    /** Poll outcomes that mean "paused" (the hub did not call the AODB). */
    public const PAUSED_OUTCOMES = ['login_backoff', 'rate_limited'];

    public static function status(?string $status): string
    {
        return $status === null ? 'No status' : (self::STATUS[$status] ?? $status);
    }

    /**
     * Tone and icon of a flight status chip: [tone, icon].
     *
     * @return array{0: string, 1: string}
     */
    public static function statusTone(?string $status): array
    {
        return match ($status) {
            'IN_FLIGHT' => ['info', 'plane'],
            'TERMINATED' => ['ok', 'check-circle'],
            'CANCELLED' => ['error', 'x'],
            'SUSPENDED' => ['warning', 'pause'],
            'SCHEDULED' => ['neutral', 'calendar'],
            default => ['disabled', 'minus'],
        };
    }

    public static function milestone(?string $milestone): ?string
    {
        return $milestone === null ? null : (self::MILESTONE[$milestone] ?? $milestone);
    }

    /** ok, failed or paused. */
    public static function pollKind(string $outcome): string
    {
        return match (true) {
            $outcome === 'ok' => 'ok',
            in_array($outcome, self::PAUSED_OUTCOMES, true) => 'paused',
            default => 'failed',
        };
    }

    /**
     * Chip for a poll outcome: [tone, icon, word].
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public static function pollChip(string $outcome): array
    {
        return match (self::pollKind($outcome)) {
            'ok' => ['ok', 'check', 'OK'],
            'paused' => ['warning', 'pause', 'Paused'],
            default => ['error', 'x', 'Failed'],
        };
    }

    public static function flow(?string $flow): string
    {
        return match ($flow) {
            'ARR' => 'Arrival',
            'DEP' => 'Departure',
            default => $flow ?? '—',
        };
    }

    /** "5 days, 3 hours", "2 minutes, 12 seconds". */
    public static function duration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $part = fn (int $n, string $unit) => $n.' '.$unit.($n === 1 ? '' : 's');
        [$d, $h, $m, $s] = [intdiv($seconds, 86400), intdiv($seconds % 86400, 3600), intdiv($seconds % 3600, 60), $seconds % 60];

        return match (true) {
            $d > 0 => $part($d, 'day').', '.$part($h, 'hour'),
            $h > 0 => $part($h, 'hour').', '.$part($m, 'minute'),
            $m > 0 => $part($m, 'minute').', '.$part($s, 'second'),
            default => $part($s, 'second'),
        };
    }

    /** Polls expected in 24 h: 24 h ÷ the interval of the poll schedule (config aodb.poll_cron). */
    public static function expectedPollsPerDay(DateTimeInterface $now): ?int
    {
        try {
            $cron = new CronExpression((string) config('aodb.poll_cron'));
            $next = $cron->getNextRunDate($now);
            $interval = $cron->getNextRunDate($next)->getTimestamp() - $next->getTimestamp();
        } catch (\Throwable) {
            return null;
        }

        return $interval > 0 ? intdiv(86400, $interval) : null;
    }

    /** Hub-owned fields (served under "hub"): plain labels. */
    public const HUB_LABELS = [
        'hub.referenceTime' => 'Reference time',
        'hub.inFeed' => 'In the feed',
        'hub.sameAs' => 'Same as (kept flight)',
        'hub.rotationNext' => 'Next departure',
        'hub.rotationPrev' => 'Previous arrival',
        'hub.rotationSource' => 'Rotation source',
        'hub.minTurnaroundMinutes' => 'Minimum turnaround (min)',
        'hub.quality' => 'Open quality flags',
        'hub.corrections' => 'Corrections',
    ];

    /** Served paths whose AODB description gives no short label. */
    public const FALLBACK_LABELS = [
        'flow' => 'Flow', 'flightNumber' => 'Flight number', 'status' => 'Status', 'milestone' => 'Milestone',
        'reg' => 'Registration', 'adepDetails' => 'Departure airport details', 'adesDetails' => 'Destination airport details',
        'divertedDetails' => 'Diversion details', 'codeShare' => 'Code shares', 'aircraftDetails' => 'Aircraft details',
        'paxDetails' => 'Passenger details', 'dlCodes' => 'Delay codes', 'gateDetails' => 'Gate details',
    ];

    /** @var array<string, string>|null */
    private static ?array $labels = null;

    /**
     * A short label for a served path, from the first line of its description in the committed
     * AODB spec ("The flight Estimated Off-Block Time (EOBT), in ISO …" → "Estimated Off-Block Time").
     */
    public static function fieldLabel(string $path): string
    {
        $name = str_ends_with($path, '.*') ? substr($path, 0, -2) : $path;
        if (isset(self::HUB_LABELS[$name])) {
            return self::HUB_LABELS[$name];
        }
        if (self::$labels === null) {
            self::$labels = [];
            $spec = json_decode((string) file_get_contents(resource_path(AodbSpec::FILE)), true);
            foreach ($spec['components']['schemas']['FlightDto']['properties'] ?? [] as $field => $property) {
                $line = trim((string) strtok((string) ($property['description'] ?? ''), "\n"));
                $line = preg_replace(['/^The (flight )?/i', '/\s*\([^)]*\)/', '/(, in |\. ).*$/', '/[.:]$/'], '', $line);
                if ($line !== '' && mb_strlen($line) <= 48) {
                    self::$labels[$field] = mb_strtoupper(mb_substr($line, 0, 1)).mb_substr($line, 1);
                }
            }
        }

        return self::$labels[$name] ?? (self::FALLBACK_LABELS[$name] ?? $name);
    }

    /** True for an ISO-8601 UTC time as the API serves it (2026-10-29T06:01:00Z). */
    public static function isUtcTime(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?Z$/', $value) === 1;
    }
}
