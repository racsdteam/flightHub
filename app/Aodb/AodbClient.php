<?php

namespace App\Aodb;

use App\Flights\Window;

interface AodbClient
{
    /**
     * GET /flights/v1/airport for the configured airport, both flows.
     */
    public function flightsAtAirport(Window $window): AodbResponse;

    /**
     * GET /flights/v1/byid for the given AODB ids (one request; callers chunk).
     *
     * @param  list<string>  $ids
     */
    public function flightsById(array $ids): AodbResponse;
}
