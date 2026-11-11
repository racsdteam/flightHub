<?php

return [

    /*
    |--------------------------------------------------------------------------
    | KIA AODB (Innov'ATM "Airport Keeper External API" v3.2.5)
    |--------------------------------------------------------------------------
    |
    | Credentials exist only in the server .env (D-003). The fixture client
    | serves tests/Fixtures/aodb/ and is refused when APP_ENV=production.
    |
    */

    'client' => env('AODB_CLIENT') ?: 'http',

    // https only; checked by the client before any request.
    'base_url' => env('AODB_BASE_URL') ?: 'https://api.app.airport-keeper.com',

    'username' => env('AODB_USERNAME'),
    'password' => env('AODB_PASSWORD'),

    'airport' => env('AODB_AIRPORT') ?: 'HRYR',

    // Seconds.
    'connect_timeout' => (int) (env('AODB_CONNECT_TIMEOUT') ?: 10),
    'timeout' => (int) (env('AODB_TIMEOUT') ?: 30),

    // Poll window: Kigali today 00:00 minus BEFORE days to plus AFTER+1 days (D-025).
    'window_days_before' => (int) (env('AODB_WINDOW_DAYS_BEFORE') ?? 1),
    'window_days_after' => (int) (env('AODB_WINDOW_DAYS_AFTER') ?? 1),

    // Every 5 minutes for now (D-030): change times are precise to 5 minutes.
    'poll_cron' => env('AODB_POLL_CRON') ?: '*/5 * * * *',

    // No login is tried for this long after a failed login: wrong logins every
    // minute could lock RAC's account.
    'login_backoff_minutes' => (int) (env('AODB_LOGIN_BACKOFF_MINUTES') ?: 15),

    'byid_chunk' => (int) (env('AODB_BYID_CHUNK') ?: 50),

    'fixture_path' => base_path('tests/Fixtures/aodb'),

];
