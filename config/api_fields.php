<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Distribution API field groups (D-039)
    |--------------------------------------------------------------------------
    |
    | A group is a list of paths: AODB paths ("x.*" = the whole object) and hub
    | fields ("hub.…"). Deny by default: a path in no group is never served,
    | new AODB fields included. identity is always given.
    |
    */

    'always' => ['identity'],

    'groups' => [
        'identity' => [
            'id', 'flow', 'flightNumber', 'fullFlightNumber', 'airlineIata', 'airlineIcao', 'status',
            'milestone', 'callsign', 'hub.referenceTime', 'hub.updatedAt', 'hub.inFeed', 'hub.sameAs',
        ],
        'route' => [
            'adepIata', 'adepIcao', 'adesIata', 'adesIcao', 'adepDetails.*', 'adesDetails.*',
            'divertedDetails.*', 'codeShare.*', 'routeIcao',
        ],
        'schedule' => ['sobt', 'sibt'],
        'times' => ['eobt', 'etot', 'aobt', 'atot', 'eldt', 'aldt', 'eibt', 'aibt'],
        'acdm' => [
            'tobt', 'tsat', 'ttot', 'ctot', 'pobt', 'ardt', 'asat', 'asrt', 'efat', 'afat', 'efet', 'afet',
            'eet', 'vtt.*', 'regulation.*',
        ],
        'resources' => [
            'pkg', 'gate', 'gateDetails.*', 'terminal', 'hall', 'counter', 'carousel', 'rwy', 'sid', 'bridge',
            'bus', 'deicing', 'zoneDetails.*', 'checkInDetails.*', 'boardingDetails.*',
            'baggageCheckInDetails.*', 'baggageDeliveryDetails.*', 'borderControl', 'securityCheckpoint',
            'publicDisplayed',
        ],
        'aircraft' => ['reg', 'acTypeIata', 'acTypeIcao', 'aircraftDetails.*', 'paxCapa', 'mtt', 'hub.minTurnaroundMinutes'],
        'passengers' => ['paxNb', 'paxDetails.*', 'bags', 'nationality', 'trafficNature'],
        'delays' => ['dlCodes.*', 'remarks'],
        'ground_handling' => ['groundHandler', 'ghrAssistance.*', 'ghrOperatorDetails.*', 'ghrRecordInfo.*'],
        'rotation' => ['linkedId', 'hub.rotationNext', 'hub.rotationPrev', 'hub.rotationSource'],
        'quality' => ['hub.quality', 'hub.corrections'],
    ],

    // Feed counts as stale when the last ok poll is older than this (D-037).
    'stale_after_minutes' => 15,

    // Refused (401) calls per IP per minute.
    'refused_per_ip_per_minute' => 30,

];
