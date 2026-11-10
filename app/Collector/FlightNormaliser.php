<?php

namespace App\Collector;

use App\Aodb\CanonicalJson;
use App\Flights\ReferenceTime;
use DateTimeImmutable;
use DateTimeZone;
use stdClass;

/**
 * One AODB flight record → the typed columns of flights / flight_versions.
 *
 * The record itself is kept whole (canonical JSON, D-024). Typed columns are
 * parsed from it; a value that does not fit leaves its column NULL and the
 * poll carries on (D-027). Twins <field>_aodb hold the value as received;
 * served columns equal the twins, then airport_code_corrections apply to
 * adepIata / adesIata, also filling the ICAO code when the record has none.
 */
final class FlightNormaliser
{
    /** column => [AODB path, type, max length] */
    public const COLUMNS = [
        'flow' => ['flow', 'string', 16],
        'linked_id' => ['linkedId', 'string', 64],
        'flight_number' => ['flightNumber', 'string', 32],
        'full_flight_number' => ['fullFlightNumber', 'string', 32],
        'airline_iata' => ['airlineIata', 'string', 16],
        'airline_icao' => ['airlineIcao', 'string', 16],
        'adep_iata_aodb' => ['adepIata', 'string', 16],
        'adep_icao_aodb' => ['adepIcao', 'string', 16],
        'ades_iata_aodb' => ['adesIata', 'string', 16],
        'ades_icao_aodb' => ['adesIcao', 'string', 16],
        'reg' => ['reg', 'string', 32],
        'ac_type_iata' => ['acTypeIata', 'string', 16],
        'ac_type_icao_aodb' => ['acTypeIcao', 'string', 16],
        'ac_type_name_aodb' => ['aircraftDetails.acTypeName', 'string', 255],
        'wtc_aodb' => ['aircraftDetails.wtc', 'string', 16],
        'mtow' => ['aircraftDetails.mtow', 'decimal', null],
        'status' => ['status', 'string', 64],
        'milestone' => ['milestone', 'string', 32],
        'sobt' => ['sobt', 'datetime', null],
        'eobt' => ['eobt', 'datetime', null],
        'tobt' => ['tobt', 'datetime', null],
        'sibt' => ['sibt', 'datetime', null],
        'etot' => ['etot', 'datetime', null],
        'atot' => ['atot', 'datetime', null],
        'aobt' => ['aobt', 'datetime', null],
        'eldt' => ['eldt', 'datetime', null],
        'aldt' => ['aldt', 'datetime', null],
        'eibt' => ['eibt', 'datetime', null],
        'aibt' => ['aibt', 'datetime', null],
        'pkg' => ['pkg', 'string', 32],
        'pax_capa_aodb' => ['paxCapa', 'int', null],
        'pax_nb' => ['paxNb', 'int', null],
        'dl_codes' => ['dlCodes', 'json', null],
    ];

    /** served column => [twin column, AODB path] (D-006) */
    public const SERVED = [
        'adep_iata' => ['adep_iata_aodb', 'adepIata'],
        'adep_icao' => ['adep_icao_aodb', 'adepIcao'],
        'ades_iata' => ['ades_iata_aodb', 'adesIata'],
        'ades_icao' => ['ades_icao_aodb', 'adesIcao'],
        'ac_type_icao' => ['ac_type_icao_aodb', 'acTypeIcao'],
        'ac_type_name' => ['ac_type_name_aodb', 'aircraftDetails.acTypeName'],
        'wtc' => ['wtc_aodb', 'aircraftDetails.wtc'],
        'pax_capa' => ['pax_capa_aodb', 'paxCapa'],
    ];

    /** Airport sides a correction applies to: IATA served column → ICAO served column. */
    private const CORRECTED_SIDES = ['adep_iata' => 'adep_icao', 'ades_iata' => 'ades_icao'];

    /**
     * Every column a version copies from the flight: record, typed, served, derived.
     */
    public static function versionColumns(): array
    {
        return ['record', ...array_keys(self::COLUMNS), ...array_keys(self::SERVED), 'reference_time', 'reference_time_source'];
    }

    /**
     * @param  array<string, object>  $corrections  active airport_code_corrections rows by aodb_value
     */
    public function __construct(private readonly array $corrections) {}

    public function normalise(stdClass $record): NormalisedFlight
    {
        $columns = ['record' => CanonicalJson::encode($record)];

        foreach (self::COLUMNS as $column => [$path, $type, $max]) {
            $columns[$column] = self::fit(CanonicalJson::get($record, $path), $type, $max);
        }

        foreach (self::SERVED as $served => [$twin]) {
            $columns[$served] = $columns[$twin];
        }

        $corrections = [];
        foreach (self::CORRECTED_SIDES as $iata => $icao) {
            $row = is_string($columns[$iata]) ? ($this->corrections[$columns[$iata]] ?? null) : null;
            if ($row === null) {
                continue;
            }
            $ref = 'airport_code_corrections:'.$row->id;
            $corrections[self::SERVED[$iata][1]] = $ref;
            $columns[$iata] = $row->corrected_iata;
            if ($columns[$icao] === null && $row->corrected_icao !== null) {
                $columns[$icao] = $row->corrected_icao;
                $corrections[self::SERVED[$icao][1]] = $ref;
            }
        }

        $times = [];
        foreach (ReferenceTime::ORDER as $fields) {
            foreach ($fields as $field) {
                $times[$field] = $columns[$field] === null ? null : new DateTimeImmutable($columns[$field].'Z');
            }
        }
        $reference = ReferenceTime::resolve($columns['flow'], $times);
        $columns['reference_time'] = $reference?->time->format('Y-m-d H:i:s');
        $columns['reference_time_source'] = $reference?->source;

        return new NormalisedFlight($columns, $corrections);
    }

    /**
     * The value as its column stores it, or null when it does not fit.
     */
    public static function fit(mixed $value, string $type, ?int $max): string|int|null
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'string' => self::fitString($value, (int) $max),
            'datetime' => self::parseTime($value)?->format('Y-m-d H:i:s'),
            'int' => self::fitInt($value),
            'decimal' => self::fitDecimal($value),
            'json' => CanonicalJson::encode($value),
        };
    }

    /**
     * An ISO-8601 date-time with a zone (Z or ±hh:mm) → UTC; anything else → null.
     */
    public static function parseTime(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || ! preg_match(
            '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2})(?:\.\d{1,9})?)?(Z|[+-]\d{2}:?\d{2})$/',
            $value,
            $m,
        )) {
            return null;
        }
        [, $y, $mo, $d, $h, $mi] = $m;
        $s = $m[6] === '' ? 0 : (int) $m[6];
        if ((int) $y < 1000 || ! checkdate((int) $mo, (int) $d, (int) $y) || (int) $h > 23 || (int) $mi > 59 || $s > 59) {
            return null;
        }

        $zone = $m[7] === 'Z' ? new DateTimeZone('UTC') : new DateTimeZone($m[7]);
        $time = new DateTimeImmutable(sprintf('%s-%s-%s %s:%s:%02d', $y, $mo, $d, $h, $mi, $s), $zone);

        return $time->setTimezone(new DateTimeZone('UTC'));
    }

    private static function fitString(mixed $value, int $max): ?string
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        return is_string($value) && mb_strlen($value) <= $max ? $value : null;
    }

    private static function fitInt(mixed $value): ?int
    {
        if (is_float($value) && floor($value) === $value) {
            $value = (int) $value;
        } elseif (is_string($value) && preg_match('/^-?\d{1,10}$/', $value)) {
            $value = (int) $value;
        }

        return is_int($value) && $value >= -2147483648 && $value <= 2147483647 ? $value : null;
    }

    private static function fitDecimal(mixed $value): ?string
    {
        if (is_string($value) && is_numeric($value)) {
            $value = (float) $value;
        }
        if (! is_int($value) && ! is_float($value)) {
            return null;
        }

        return abs($value) < 1e9 ? number_format($value, 3, '.', '') : null;
    }
}
