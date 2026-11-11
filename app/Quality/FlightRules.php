<?php

namespace App\Quality;

use App\Aodb\AodbSpec;
use App\Aodb\CanonicalJson;
use App\Collector\FlightNormaliser;
use Carbon\CarbonImmutable;
use stdClass;

/**
 * The data-quality rules (D-009, D-032). Pure: a flights row (with its stored
 * record) and the clock in, conditions out. Lateness is delay and belongs to
 * consumers; only early actuals are flagged.
 */
final class FlightRules
{
    public const DUPLICATE_SUSPECTED = 'duplicate_suspected';

    public const FORMAT_INVALID = 'format_invalid';

    public const REQUIRED_MISSING = 'required_missing';

    public const VALUE_UNFIT = 'value_unfit';

    public const SUFFIXED_FLIGHT_NUMBER = 'suffixed_flight_number';

    public const ACTUAL_EARLY = 'actual_early';

    public const NO_ACTUAL_AFTER_SCHEDULE = 'no_actual_after_schedule';

    public const IN_FLIGHT_STALE = 'in_flight_stale';

    public const TYPE_MISSING = 'type_missing';

    public const CAPACITY_MISSING = 'capacity_missing';

    public const NO_TIMES = 'no_times';

    public const STAND_UNKNOWN = 'stand_unknown';

    /** no_times applies to flights seen within this many hours. */
    public const NO_TIMES_SEEN_HOURS = 24;

    /** Statuses after which a missing actual is not a quality problem. */
    private const NO_ACTUAL_EXEMPT = ['IN_FLIGHT', 'TERMINATED', 'CANCELLED'];

    /**
     * @param  array<string, int>  $thresholds  config('quality')
     * @param  list<string>  $stands  active stands codes; empty = stand_unknown inactive
     */
    public function __construct(
        private readonly array $thresholds,
        private readonly array $stands = [],
    ) {}

    /**
     * Every condition true for one flight now (duplicates excluded).
     *
     * @return list<Condition>
     */
    public function check(object $flight, CarbonImmutable $now): array
    {
        $record = CanonicalJson::decode($flight->record);
        $conditions = [];

        foreach ($this->formatInvalid($record) as $condition) {
            $conditions[] = $condition;
        }

        foreach (AodbSpec::requiredFlightFields() as $field) {
            if (CanonicalJson::get($record, $field) === null) {
                $conditions[] = new Condition(self::REQUIRED_MISSING, $field, null, []);
            }
        }

        // D-027: the record holds a value, its typed column does not.
        foreach (FlightNormaliser::COLUMNS as $column => [$path]) {
            $value = CanonicalJson::get($record, $path);
            if ($value !== null && $flight->{$column} === null) {
                $conditions[] = new Condition(self::VALUE_UNFIT, $path, null, ['value' => $value, 'column' => $column]);
            }
        }

        $number = CanonicalJson::get($record, 'flightNumber');
        if (is_string($number) && preg_match('/[A-Za-z]$/', $number)) {
            $conditions[] = new Condition(self::SUFFIXED_FLIGHT_NUMBER, null, null, ['flightNumber' => $number]);
        }

        [$scheduledField, $actualField, $actualFields] = match ($flight->flow) {
            'DEP' => ['sobt', 'aobt', ['aobt', 'atot']],
            'ARR' => ['sibt', 'aibt', ['aldt', 'aibt']],
            default => [null, null, []],
        };

        if ($scheduledField !== null) {
            $scheduled = self::time($flight->{$scheduledField});
            $actual = self::time($flight->{$actualField});

            if ($scheduled !== null && $actual !== null
                && $actual->lt($scheduled->subMinutes($this->thresholds['actual_early_minutes']))) {
                $conditions[] = new Condition(self::ACTUAL_EARLY, null, null, [
                    $scheduledField => self::iso($scheduled),
                    $actualField => self::iso($actual),
                    'minutes_early' => (int) $actual->diffInMinutes($scheduled),
                ]);
            }

            $hasActual = array_filter($actualFields, fn ($field) => $flight->{$field} !== null) !== [];
            if ($scheduled !== null && ! $hasActual
                && ! in_array($flight->status, self::NO_ACTUAL_EXEMPT, true)
                && $now->gt($scheduled->addMinutes($this->thresholds['no_actual_minutes']))) {
                $conditions[] = new Condition(self::NO_ACTUAL_AFTER_SCHEDULE, null, null, [
                    $scheduledField => self::iso($scheduled),
                    'status' => $flight->status,
                    'minutes_after_schedule' => (int) $scheduled->diffInMinutes($now),
                ]);
            }
        }

        if ($flight->flow === 'ARR' && $flight->status === 'IN_FLIGHT' && $flight->aldt === null && $flight->aibt === null) {
            $estimateField = $flight->eibt !== null ? 'eibt' : ($flight->eldt !== null ? 'eldt' : null);
            $estimate = $estimateField === null ? null : self::time($flight->{$estimateField});
            if ($estimate !== null && $now->gt($estimate->addMinutes($this->thresholds['in_flight_stale_minutes']))) {
                $conditions[] = new Condition(self::IN_FLIGHT_STALE, null, null, [
                    $estimateField => self::iso($estimate),
                    'minutes_after_estimate' => (int) $estimate->diffInMinutes($now),
                ]);
            }
        }

        // No window position: no time at all, yet seen recently.
        if ($flight->reference_time === null && $flight->in_feed
            && CarbonImmutable::parse($flight->last_seen_at, 'UTC')->gte($now->subHours(self::NO_TIMES_SEEN_HOURS))) {
            $conditions[] = new Condition(self::NO_TIMES, null, null, ['last_seen_at' => self::iso(CarbonImmutable::parse($flight->last_seen_at, 'UTC'))]);
        }

        if ($this->stands !== [] && $flight->pkg !== null && ! in_array($flight->pkg, $this->stands, true)) {
            $conditions[] = new Condition(self::STAND_UNKNOWN, null, null, ['pkg' => $flight->pkg]);
        }

        if ($flight->ac_type_icao === null) {
            $conditions[] = new Condition(self::TYPE_MISSING, null, null, []);
        }

        if ($flight->pax_capa === null) {
            $conditions[] = new Condition(self::CAPACITY_MISSING, null, null, []);
        }

        return $conditions;
    }

    /**
     * Same full flight number and flow, reference times within the duplicate
     * window: one condition on each flight, naming the other.
     *
     * @param  iterable<object>  $flights
     * @return array<int, list<Condition>> flight id → conditions
     */
    public function duplicates(iterable $flights): array
    {
        $groups = [];
        foreach ($flights as $flight) {
            if ($flight->full_flight_number !== null && $flight->reference_time !== null) {
                $groups[$flight->full_flight_number.'|'.$flight->flow][] = $flight;
            }
        }

        $limit = $this->thresholds['duplicate_window_hours'] * 3600;
        $found = [];
        foreach ($groups as $group) {
            foreach ($group as $a) {
                foreach ($group as $b) {
                    $gap = abs(self::time($a->reference_time)->getTimestamp() - self::time($b->reference_time)->getTimestamp());
                    if ($a->id !== $b->id && $gap <= $limit) {
                        $found[$a->id][] = new Condition(self::DUPLICATE_SUSPECTED, null, $b, [
                            'related_aodb_id' => $b->aodb_id,
                            'reference_time' => self::iso(self::time($a->reference_time)),
                            'related_reference_time' => self::iso(self::time($b->reference_time)),
                        ]);
                    }
                }
            }
        }

        return $found;
    }

    /**
     * AODB values violating the spec's pattern, checked on the value as
     * received (a correction of the served value does not hide it).
     *
     * @return list<Condition>
     */
    private function formatInvalid(stdClass $record): array
    {
        $conditions = [];
        foreach (AodbSpec::patterns() as $path => ['pattern' => $pattern, 'items' => $items]) {
            $value = CanonicalJson::get($record, $path);
            $values = $items ? (is_array($value) ? $value : []) : [$value];
            foreach ($values as $item) {
                // JSON Schema patterns apply to strings only.
                if (is_string($item) && preg_match('~'.$pattern.'~u', $item) === 0) {
                    $conditions[] = new Condition(self::FORMAT_INVALID, $path, null, ['value' => $item, 'pattern' => $pattern]);
                    break;
                }
            }
        }

        return $conditions;
    }

    private static function time(?string $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value, 'UTC');
    }

    private static function iso(CarbonImmutable $time): string
    {
        return $time->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
