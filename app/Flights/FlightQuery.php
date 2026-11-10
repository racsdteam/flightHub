<?php

namespace App\Flights;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Flights of a window, now or as the hub knew them at a past moment (D-022).
 * Runs on the runtime (default) connection.
 */
final class FlightQuery
{
    /**
     * asOf null: current state from flights, by reference_time.
     * asOf set: for each flight its latest version with valid_from <= asOf
     * (ties: higher id), kept if that version's reference_time is in the window.
     * Candidates use flight_versions (reference_time, valid_from); the NOT EXISTS
     * probe uses flight_versions (flight_id, valid_from, id). Both are forced: on
     * wide windows the optimizer otherwise picks a full scan, which is slower.
     *
     * @return Collection<int, object>
     */
    public function flightsInWindow(Window $window, ?DateTimeInterface $asOf = null): Collection
    {
        $from = $window->from->format('Y-m-d H:i:s');
        $to = $window->to->format('Y-m-d H:i:s');

        if ($asOf === null) {
            return collect(DB::select(
                'SELECT * FROM flights
                 WHERE reference_time >= ? AND reference_time < ?
                 ORDER BY reference_time, id',
                [$from, $to],
            ));
        }

        $at = DateTimeImmutable::createFromInterface($asOf)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.u');

        return collect(DB::select(
            'SELECT f.aodb_id, v.*
             FROM flight_versions v FORCE INDEX (flight_versions_reference_time_valid_from_index)
             JOIN flights f ON f.id = v.flight_id
             WHERE v.reference_time >= ? AND v.reference_time < ?
               AND v.valid_from <= ?
               AND NOT EXISTS (
                   SELECT 1 FROM flight_versions later FORCE INDEX (flight_versions_flight_id_valid_from_id_index)
                   WHERE later.flight_id = v.flight_id
                     AND later.valid_from <= ?
                     AND (later.valid_from > v.valid_from
                          OR (later.valid_from = v.valid_from AND later.id > v.id))
               )
             ORDER BY v.reference_time, v.flight_id',
            [$from, $to, $at, $at],
        ));
    }
}
