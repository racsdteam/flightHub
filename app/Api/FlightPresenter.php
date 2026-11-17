<?php

namespace App\Api;

use App\Aodb\CanonicalJson;
use App\Collector\FlightNormaliser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The API representation of flights and change events for one consumer.
 *
 * A flight = the allowed part of its record (nested objects as received;
 * every allowed top-level field present, null when absent), corrected served
 * values under their AODB names, and a "hub" object. Never internal ids:
 * flights are named by their AODB id, links carry AODB ids, reference rows
 * their natural keys.
 */
final class FlightPresenter
{
    /** @var array<int, string> flights.id → aodb_id, for links */
    private array $aodbIds = [];

    /** @var array<int, list<array{rule: string, field: ?string, since: string}>> */
    private array $quality = [];

    /** @var array<int, string> flights.id → valid_from of the current version */
    private array $updatedAt = [];

    /** @var array<string, string> "table:id" → natural key */
    private array $references = [];

    private ?FlightNormaliser $normaliser = null;

    /** @var array<string, object> active aircraft_types by "airline|type" */
    private array $aircraftTypes = [];

    private function __construct(private readonly FieldSet $fields) {}

    /**
     * Load what the given flight or version rows need, once.
     *
     * @param  Collection<int, object>  $rows
     */
    public static function for(FieldSet $fields, Collection $rows, ?CarbonImmutable $asOf = null): self
    {
        $presenter = new self($fields);
        $flightId = fn (object $row) => (int) ($row->flight_id ?? $row->id);

        $linked = $rows->flatMap(fn ($r) => [$r->same_as_flight_id, $r->rotation_next_flight_id, $r->rotation_prev_flight_id])->filter()->unique()->all();
        if ($linked !== []) {
            $presenter->aodbIds = DB::table('flights')->whereIn('id', $linked)->pluck('aodb_id', 'id')->all();
        }

        if ($fields->allows('hub.quality')) {
            $presenter->quality = self::openFlags($rows->map($flightId)->all(), $asOf);
        }

        if ($fields->allows('hub.corrections')) {
            $presenter->normaliser = FlightNormaliser::fromDatabase();
            foreach (DB::table('airport_code_corrections')->get(['id', 'aodb_value']) as $row) {
                $presenter->references['airport_code_corrections:'.$row->id] = 'airport_code_corrections:'.$row->aodb_value;
            }
            foreach (DB::table('aircraft_types')->get(['id', 'airline_iata', 'ac_type_iata']) as $row) {
                $presenter->references['aircraft_types:'.$row->id] = "aircraft_types:{$row->airline_iata}/{$row->ac_type_iata}";
            }
        }

        // hub.updatedAt: when the hub observed the version served. A version row (as_of, events)
        // carries its own valid_from; for a flights row, that of its current version.
        if ($fields->allows('hub.updatedAt')) {
            $current = $rows->filter(fn ($r) => ! property_exists($r, 'valid_from'))->map(fn ($r) => (int) $r->id)->all();
            if ($current !== []) {
                $presenter->updatedAt = DB::table('flight_versions as v')
                    ->join('flights as f', fn ($join) => $join->on('f.id', '=', 'v.flight_id')->on('f.current_version_no', '=', 'v.version_no'))
                    ->whereIn('f.id', $current)
                    ->pluck('v.valid_from', 'v.flight_id')->all();
            }
        }

        if ($fields->allows('hub.minTurnaroundMinutes')) {
            $presenter->aircraftTypes = DB::table('aircraft_types')->where('active', true)->get()
                ->keyBy(fn ($row) => $row->airline_iata.'|'.$row->ac_type_iata)->all();
        }

        return $presenter;
    }

    /**
     * One flight from a flights row or a flight_versions row.
     *
     * @return array<string, mixed>
     */
    public function flight(object $row): array
    {
        $record = CanonicalJson::decode($row->record);
        $out = $this->fields->filter($record, padded: true);

        // Corrected served values replace the AODB value under its name.
        foreach (FlightNormaliser::SERVED as $column => [$twin, $path]) {
            $served = $row->{$column};
            $received = $row->{$twin};
            if (! $this->fields->allows($path) || (($served === null) === ($received === null) && (string) $served === (string) $received)) {
                continue;
            }
            $value = $column === 'pax_capa' && $served !== null ? (int) $served : $served;
            if (str_contains($path, '.')) {
                [$object, $key] = explode('.', $path, 2);
                $nested = ($out[$object] ?? null) instanceof stdClass ? clone $out[$object] : new stdClass;
                $nested->{$key} = $value;
                $out[$object] = $nested;
            } else {
                $out[$path] = $value;
            }
        }

        $hub = [];
        foreach ($this->fields->hubFields() as $field) {
            $hub[$field] = match ($field) {
                'referenceTime' => self::iso($row->reference_time),
                'updatedAt' => self::iso(property_exists($row, 'valid_from') ? $row->valid_from : ($this->updatedAt[(int) $row->id] ?? null)),
                'inFeed' => (bool) $row->in_feed,
                'sameAs' => $this->aodbId($row->same_as_flight_id),
                'rotationNext' => $this->aodbId($row->rotation_next_flight_id),
                'rotationPrev' => $this->aodbId($row->rotation_prev_flight_id),
                'rotationSource' => $row->rotation_source,
                'minTurnaroundMinutes' => $this->minTurnaround($row, $record),
                'quality' => $this->quality[(int) ($row->flight_id ?? $row->id)] ?? [],
                'corrections' => $this->corrections($row, $record),
                default => null,
            };
        }
        $out['hub'] = (object) $hub;

        return $out;
    }

    /**
     * Visible change events (each with aodb_id), in order. first_seen's value
     * is the flight in the same shape as /flights data, from the version it
     * produced; its hub.quality is empty (flags are evaluated after the poll
     * that first saw the flight).
     *
     * @param  Collection<int, object>  $events
     * @return list<array<string, mixed>>
     */
    public static function events(FieldSet $fields, Collection $events): array
    {
        $ids = $events->where('kind', 'first_seen')->pluck('version_id')->unique()->all();
        $versions = $ids === [] ? collect() : DB::table('flight_versions')->whereIn('id', $ids)->get()->keyBy('id');
        $presenter = self::for($fields, $versions->values());
        $presenter->quality = [];

        return $events->map(fn ($e) => $presenter->event($e, $versions[$e->version_id] ?? null))->filter()->values()->all();
    }

    /**
     * A change event, or null when its field is outside the consumer's groups.
     *
     * @return array<string, mixed>|null
     */
    private function event(object $event, ?object $version): ?array
    {
        if ($event->field !== null && ! $this->fields->allows($event->field)) {
            return null;
        }

        $old = $event->old_value === null ? null : json_decode($event->old_value);
        $new = $event->kind === 'first_seen' && $version !== null
            ? $this->flight($version)
            : ($event->new_value === null ? null : json_decode($event->new_value));

        return [
            'cursor' => (int) $event->id,
            'aodbId' => $event->aodb_id,
            'observedAt' => self::iso($event->observed_at),
            'kind' => $event->kind,
            'field' => $event->field,
            'old' => $old,
            'new' => $new,
            'source' => $event->source,
        ];
    }

    private function aodbId(int|string|null $flightId): ?string
    {
        return $flightId === null ? null : ($this->aodbIds[(int) $flightId] ?? null);
    }

    /**
     * DEP: the AODB's mtt, else the aircraft type's minimum turnaround; null otherwise.
     */
    private function minTurnaround(object $row, stdClass $record): ?int
    {
        if ($row->flow !== 'DEP') {
            return null;
        }
        $mtt = $record->mtt ?? null;
        if (is_int($mtt)) {
            return $mtt;
        }
        $type = $row->ac_type_iata === null ? null
            : ($this->aircraftTypes[$row->airline_iata.'|'.$row->ac_type_iata] ?? $this->aircraftTypes['*|'.$row->ac_type_iata] ?? null);

        return $type?->min_turnaround_minutes === null ? null : (int) $type->min_turnaround_minutes;
    }

    /**
     * Per corrected field: the AODB value, the served value and the reference row.
     *
     * @return list<array{field: string, aodb: mixed, served: mixed, reference: ?string}>
     */
    private function corrections(object $row, stdClass $record): array
    {
        $refs = $this->normaliser?->normalise($record)->corrections ?? [];
        $out = [];
        foreach (FlightNormaliser::SERVED as $column => [$twin, $path]) {
            $served = $row->{$column};
            $received = $row->{$twin};
            if (($served === null) === ($received === null) && (string) $served === (string) $received) {
                continue;
            }
            $out[] = [
                'field' => $path,
                'aodb' => $received,
                'served' => $served,
                'reference' => isset($refs[$path]) ? ($this->references[$refs[$path]] ?? null) : null,
            ];
        }

        return $out;
    }

    /**
     * Open flags per flight, now or as of a moment, from the flag events.
     *
     * @param  list<int>  $flightIds
     * @return array<int, list<array{rule: string, field: ?string, since: string}>>
     */
    private static function openFlags(array $flightIds, ?CarbonImmutable $asOf): array
    {
        if ($flightIds === []) {
            return [];
        }

        $events = DB::table('quality_flag_events as e')
            ->join('quality_flags as f', 'f.id', '=', 'e.flag_id')
            ->whereIn('f.flight_id', $flightIds)
            ->whereIn('e.kind', ['detected', 'reopened', 'cleared'])
            ->when($asOf, fn ($q) => $q->where('e.at', '<=', $asOf->format('Y-m-d H:i:s.u')))
            ->orderBy('e.id')
            ->get(['e.flag_id', 'e.kind', 'e.at', 'f.flight_id', 'f.rule', 'f.field']);

        $open = [];
        foreach ($events as $event) {
            if ($event->kind === 'cleared') {
                unset($open[$event->flag_id]);
            } else {
                $open[$event->flag_id] = $event;
            }
        }

        $flags = [];
        foreach ($open as $event) {
            $flags[(int) $event->flight_id][] = [
                'rule' => $event->rule,
                'field' => $event->field,
                'since' => CarbonImmutable::parse($event->at, 'UTC')->format('Y-m-d\TH:i:s\Z'),
            ];
        }

        return $flags;
    }

    public static function iso(?string $datetime): ?string
    {
        return $datetime === null ? null : CarbonImmutable::parse($datetime, 'UTC')->format('Y-m-d\TH:i:s\Z');
    }
}
