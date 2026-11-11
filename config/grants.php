<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Runtime user grants registry (D-013)
    |--------------------------------------------------------------------------
    |
    | Every table → the runtime user's privileges on it. Deny-by-default: a
    | table missing from this list is an error for hub:grants-sql and
    | hub:check-grants. History tables never get UPDATE; raw_responses gets
    | DELETE, which its trigger limits to rows older than 90 days.
    |
    */

    'tables' => [
        'polls' => ['SELECT', 'INSERT'],
        'raw_responses' => ['SELECT', 'INSERT', 'DELETE'],
        'flights' => ['SELECT', 'INSERT', 'UPDATE'],
        'flight_versions' => ['SELECT', 'INSERT'],
        'change_events' => ['SELECT', 'INSERT'],

        // Reference data, edited in the admin screens: deactivated, never deleted.
        'airport_code_corrections' => ['SELECT', 'INSERT', 'UPDATE'],
        'aircraft_types' => ['SELECT', 'INSERT', 'UPDATE'],
        'stands' => ['SELECT', 'INSERT', 'UPDATE'],

        // Quality flags: current state, never deleted; their events are append-only.
        'quality_flags' => ['SELECT', 'INSERT', 'UPDATE'],
        'quality_flag_events' => ['SELECT', 'INSERT'],

        // Distribution API: consumers and keys are disabled / revoked, never deleted;
        // api_calls is append-only, DELETE only after 365 days (trigger).
        'consumers' => ['SELECT', 'INSERT', 'UPDATE'],
        'api_keys' => ['SELECT', 'INSERT', 'UPDATE'],
        'api_calls' => ['SELECT', 'INSERT', 'DELETE'],

        'cache' => ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
        'cache_locks' => ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
        'sessions' => ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
        'jobs' => ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
        'job_batches' => ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
        'failed_jobs' => ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
        // Admin accounts: never deleted (deactivate). No e-mail password reset in v1.
        'users' => ['SELECT', 'INSERT', 'UPDATE'],
        'password_reset_tokens' => [],
        'admin_audit' => ['SELECT', 'INSERT'],

        'migrations' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Grant host
    |--------------------------------------------------------------------------
    |
    | Host part of the runtime account in the SQL printed by hub:grants-sql
    | (production: localhost). Overridden per run with --host.
    |
    */

    'host' => env('DB_GRANT_HOST') ?: 'localhost',

];
