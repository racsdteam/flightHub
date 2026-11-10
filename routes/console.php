<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// AODB poll: cron from AODB_POLL_CRON (default every minute); lock expires after 5 min.
Schedule::command('hub:poll')->cron(config('aodb.poll_cron'))->withoutOverlapping(5);

// Raw responses are kept 90 days (D-008).
Schedule::command('hub:prune-raw')->daily();
