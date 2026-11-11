<?php

use Illuminate\Support\Facades\Schedule;

// AODB poll: cron from AODB_POLL_CRON (default every 5 minutes, D-030); lock expires after 5 min.
Schedule::command('hub:poll')->cron(config('aodb.poll_cron'))->withoutOverlapping(5);

// Raw responses are kept 90 days (D-008).
Schedule::command('hub:prune-raw')->daily();
