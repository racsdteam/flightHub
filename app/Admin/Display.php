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

    /**
     * Short human labels for served paths (top-level and nested; list items without an index).
     * The AODB description is only a title tooltip (description()); the path is shown under the label.
     */
    public const LABELS = [
        // identity
        'id' => 'AODB id', 'flow' => 'Direction', 'flightNumber' => 'Flight number', 'fullFlightNumber' => 'Flight',
        'airlineIata' => 'Airline (IATA)', 'airlineIcao' => 'Airline (ICAO)', 'status' => 'Status', 'milestone' => 'Milestone',
        'callsign' => 'ATC callsign', 'hub.referenceTime' => 'Reference time', 'hub.inFeed' => 'In the feed', 'hub.sameAs' => 'Same as (kept flight)',
        // route
        'adepIata' => 'From (IATA)', 'adepIcao' => 'From (ICAO)', 'adesIata' => 'To (IATA)', 'adesIcao' => 'To (ICAO)',
        'adepDetails' => 'Departure airport', 'adepDetails.adepName' => 'Name', 'adepDetails.firstAdepIata' => 'First departure (IATA)', 'adepDetails.firstAdepIcao' => 'First departure (ICAO)',
        'adesDetails' => 'Arrival airport', 'adesDetails.adesName' => 'Name', 'adesDetails.lastAdesIata' => 'Final arrival (IATA)', 'adesDetails.lastAdesIcao' => 'Final arrival (ICAO)',
        'divertedDetails' => 'Diversion', 'divertedDetails.filedAdesIata' => 'Filed arrival (IATA)', 'divertedDetails.filedAdesIcao' => 'Filed arrival (ICAO)',
        'divertedDetails.divertedAdesIata' => 'Diverted to (IATA)', 'divertedDetails.divertedAdesIcao' => 'Diverted to (ICAO)',
        'codeShare' => 'Code share', 'codeShare.operatingAirlineIata' => 'Operating airline (IATA)', 'codeShare.operatingAirlineIcao' => 'Operating airline (ICAO)',
        'codeShare.flightNumbers' => 'Flight numbers', 'routeIcao' => 'ICAO route',
        // schedule, times
        'sobt' => 'Scheduled off-block (SOBT)', 'sibt' => 'Scheduled in-block (SIBT)',
        'eobt' => 'Estimated off-block (EOBT)', 'etot' => 'Estimated take-off (ETOT)', 'aobt' => 'Actual off-block (AOBT)', 'atot' => 'Actual take-off (ATOT)',
        'eldt' => 'Estimated landing (ELDT)', 'aldt' => 'Actual landing (ALDT)', 'eibt' => 'Estimated in-block (EIBT)', 'aibt' => 'Actual in-block (AIBT)',
        // A-CDM
        'tobt' => 'Target off-block (TOBT)', 'tsat' => 'Target start-up approval (TSAT)', 'ttot' => 'Target take-off (TTOT)', 'ctot' => 'Calculated take-off (CTOT)',
        'pobt' => 'Predicted off-block (POBT)', 'ardt' => 'Aircraft ready (ARDT)', 'asat' => 'Actual start-up approval (ASAT)', 'asrt' => 'Start-up request (ASRT)',
        'efat' => 'Estimated final approach (EFAT)', 'afat' => 'Actual final approach (AFAT)', 'efet' => 'Estimated FIR entry (EFET)', 'afet' => 'Actual FIR entry (AFET)',
        'eet' => 'Flight duration (EET, min)', 'vtt' => 'Variable taxi time (VTT)', 'vtt.estimated' => 'Estimated (min)', 'vtt.actual' => 'Actual (min)',
        'regulation' => 'ATFM regulation', 'regulation.regul' => 'Regulation id', 'regulation.cause' => 'Cause', 'regulation.reason' => 'Reason',
        'regulation.description' => 'Description', 'regulation.remark' => 'Remark',
        // resources
        'pkg' => 'Stand', 'gate' => 'Gate', 'gateDetails' => 'Gate times', 'gateDetails.estimatedOpenTime' => 'Estimated open', 'gateDetails.actualOpenTime' => 'Actual open',
        'gateDetails.estimatedCloseTime' => 'Estimated close', 'gateDetails.actualCloseTime' => 'Actual close', 'gateDetails.status' => 'Status',
        'terminal' => 'Terminal', 'hall' => 'Hall', 'counter' => 'Check-in counter', 'carousel' => 'Baggage belt', 'rwy' => 'Runway', 'sid' => 'Departure procedure (SID)',
        'bridge' => 'Bridge needed', 'bus' => 'Bus needed', 'deicing' => 'De-icing needed',
        'zoneDetails' => 'Zones', 'zoneDetails.paxZone' => 'Passenger zone', 'zoneDetails.parkingZone' => 'Parking zone', 'zoneDetails.gateZone' => 'Gate zone',
        'checkInDetails' => 'Check-in', 'checkInDetails.openTime' => 'Opens', 'checkInDetails.closeTime' => 'Closes', 'checkInDetails.status' => 'Status',
        'boardingDetails' => 'Boarding', 'boardingDetails.startTime' => 'Starts', 'boardingDetails.endTime' => 'Ends', 'boardingDetails.status' => 'Status',
        'baggageCheckInDetails' => 'Baggage check-in', 'baggageCheckInDetails.belt' => 'Belt', 'baggageCheckInDetails.inspectionSystem' => 'Inspection system',
        'baggageDeliveryDetails' => 'Baggage delivery', 'baggageDeliveryDetails.openTime' => 'Belt opens', 'baggageDeliveryDetails.closeTime' => 'Belt closes',
        'baggageDeliveryDetails.firstBagTime' => 'First bag', 'baggageDeliveryDetails.lastBagTime' => 'Last bag', 'baggageDeliveryDetails.status' => 'Status',
        'borderControl' => 'Border control', 'securityCheckpoint' => 'Security checkpoint', 'publicDisplayed' => 'Shown on public displays',
        // aircraft
        'reg' => 'Aircraft registration', 'acTypeIata' => 'Aircraft type (IATA)', 'acTypeIcao' => 'Aircraft type (ICAO)',
        'aircraftDetails' => 'Aircraft details', 'aircraftDetails.acTypeName' => 'Type name', 'aircraftDetails.wtc' => 'Wake category', 'aircraftDetails.mtow' => 'Maximum take-off weight (t)',
        'paxCapa' => 'Passenger capacity', 'mtt' => 'Minimum turnaround (MTT, min)', 'hub.minTurnaroundMinutes' => 'Minimum turnaround used (min)',
        // passengers
        'paxNb' => 'Passengers', 'paxDetails' => 'Passenger details', 'paxDetails.estimated' => 'Estimated', 'paxDetails.um' => 'Unaccompanied minors',
        'paxDetails.pmr' => 'Reduced mobility', 'paxDetails.vip' => 'VIP', 'paxDetails.other' => 'Other categories', 'paxDetails.remarks' => 'Remarks',
        'bags' => 'Baggage weight (kg)', 'nationality' => 'Nationality sector', 'trafficNature' => 'Traffic nature',
        // delays
        'dlCodes' => 'Delay codes', 'dlCodes.aodb' => 'From the AODB', 'dlCodes.typeb' => 'From Type B messages', 'dlCodes.ais' => 'From the AIS',
        'code' => 'Code', 'delay' => 'Delay (min)', 'info' => 'Information', 'remarks' => 'Remarks',
        // ground handling
        'groundHandler' => 'Ground handler', 'ghrAssistance' => 'Handling assistance', 'ghrAssistance.selected' => 'Selected', 'ghrAssistance.other' => 'Other',
        'type' => 'Type', 'remark' => 'Remark', 'ghrOperatorDetails' => 'Handling operator', 'ghrOperatorDetails.name' => 'Name',
        'ghrOperatorDetails.country' => 'Country', 'ghrOperatorDetails.id' => 'Operator id',
        'ghrRecordInfo' => 'Handling record', 'ghrRecordInfo.status' => 'Status', 'ghrRecordInfo.assistanceValidated' => 'Assistance validated',
        // rotation, quality
        'linkedId' => 'Linked flight (AODB id)', 'hub.rotationNext' => 'Next departure', 'hub.rotationPrev' => 'Previous arrival', 'hub.rotationSource' => 'Rotation source',
        'hub.quality' => 'Open quality flags', 'hub.corrections' => 'Corrections',
    ];

    /** @var array<string, string>|null dotted path → first line of its AODB description */
    private static ?array $descriptions = null;

    private static function name(string $path): string
    {
        return preg_replace('/\.\d+(?=\.|$)/', '', str_ends_with($path, '.*') ? substr($path, 0, -2) : $path);
    }

    /** A short human label for a served path (nested paths too); never the AODB's own wording. */
    public static function fieldLabel(string $path): string
    {
        $name = self::name($path);
        if (isset(self::LABELS[$name])) {
            return self::LABELS[$name];
        }
        $last = substr((string) strrchr('.'.$name, '.'), 1);

        return self::LABELS[$last] ?? ucfirst(strtolower(trim((string) preg_replace('/(?<!^)[A-Z]/', ' $0', $last))));
    }

    /** The first line of the AODB description of a path, for a title tooltip ('' when none). */
    public static function description(string $path): string
    {
        if (self::$descriptions === null) {
            self::$descriptions = [];
            $schemas = json_decode((string) file_get_contents(resource_path(AodbSpec::FILE)), true)['components']['schemas'] ?? [];
            $walk = function (array $schema, string $prefix, int $depth) use (&$walk, $schemas): void {
                foreach ($schema['properties'] ?? [] as $field => $property) {
                    $line = trim((string) strtok((string) ($property['description'] ?? ''), "\n"));
                    if ($line !== '') {
                        self::$descriptions[$prefix.$field] = $line;
                    }
                    $ref = $property['$ref'] ?? ($property['items']['$ref'] ?? null);
                    if ($ref !== null && $depth < 3 && isset($schemas[basename($ref)])) {
                        $walk($schemas[basename($ref)], $prefix.$field.'.', $depth + 1);
                    }
                }
            };
            $walk($schemas['FlightDto'] ?? [], '', 0);
        }

        return self::$descriptions[self::name($path)] ?? '';
    }

    /** null, '', [] and objects whose every value is empty. */
    public static function isEmpty(mixed $value): bool
    {
        if (is_object($value)) {
            $value = (array) $value;
        }

        return match (true) {
            $value === null, $value === '', $value === [] => true,
            is_array($value) => array_reduce($value, fn ($empty, $v) => $empty && self::isEmpty($v), true),
            default => false,
        };
    }

    /** Objects (stdClass) as arrays, recursively. */
    public static function plain(mixed $value): mixed
    {
        return is_object($value) || is_array($value) ? json_decode((string) json_encode($value), true) : $value;
    }

    /**
     * The rows a value shows as on the flight page: a scalar is one row; an object is a heading row
     * with its fields indented under it; a list of objects is one table row; a list of scalars is
     * one row. Empty nested fields are left out unless $withEmpty.
     *
     * @return list<array{path: string, label: string, depth: int, kind: string, value: mixed}>
     */
    public static function rows(string $path, mixed $value, bool $withEmpty, int $depth = 0): array
    {
        $value = self::plain($value);
        $name = self::name($path);
        $row = ['path' => $name, 'label' => self::fieldLabel($name), 'depth' => $depth];
        if (! is_array($value) || $value === []) {
            return [$row + ['kind' => 'value', 'value' => $value === [] ? null : $value]];
        }
        if (array_is_list($value)) {
            $objects = array_filter($value, 'is_array');

            return [$row + ($objects === []
                ? ['kind' => 'value', 'value' => implode(', ', array_map(fn ($v) => self::text($v), $value))]
                : ['kind' => 'table', 'value' => array_values($objects)])];
        }
        $rows = [$row + ['kind' => 'group', 'value' => null]];
        foreach ($value as $key => $child) {
            if ($withEmpty || ! self::isEmpty($child)) {
                array_push($rows, ...self::rows($name.'.'.$key, $child, $withEmpty, $depth + 1));
            }
        }

        return $rows;
    }

    /**
     * A value in words: null —, booleans Yes / No, strings as they are (no JSON quotes); an object
     * as "Label: value · …" with labels from its path (e.g. "aircraftDetails").
     */
    public static function text(mixed $value, ?string $path = null): string
    {
        $prefix = $path === null ? '' : self::name($path).'.';

        return match (true) {
            $value === null || $value === '' || $value === [] => '—',
            is_bool($value) => $value ? 'Yes' : 'No',
            is_scalar($value) => (string) $value,
            is_array($value) && array_is_list($value) => implode('; ', array_map(fn ($v) => self::text($v, $path), $value)),
            is_array($value) => implode(' · ', array_map(fn ($k, $v) => self::fieldLabel($prefix.$k).': '.self::text($v, $prefix.$k), array_keys($value), $value)),
            default => self::text(self::plain($value), $path),
        };
    }

    /** A value stored as JSON in a change event, decoded (a string that is not JSON stays as it is). */
    public static function decode(?string $json): mixed
    {
        if ($json === null) {
            return null;
        }
        $value = json_decode($json, true);

        return json_last_error() === JSON_ERROR_NONE ? $value : $json;
    }

    /** True for an ISO-8601 UTC time as the API serves it (2026-10-29T06:01:00Z). */
    public static function isUtcTime(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?Z$/', $value) === 1;
    }
}
