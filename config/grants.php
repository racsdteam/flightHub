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

        'cache' => ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
        'cache_locks' => ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
        'sessions' => ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
        'jobs' => ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
        'job_batches' => ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
        'failed_jobs' => ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
        'users' => ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
        'password_reset_tokens' => ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],

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
