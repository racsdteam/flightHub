<?php

use Illuminate\Support\Facades\Schedule;

// AODB poll: cron from AODB_POLL_CRON (default every 5 minutes, D-030); lock expires after 5 min.
Schedule::command('hub:poll')->cron(config('aodb.poll_cron'))->withoutOverlapping(5);

// Raw responses are kept 90 days (D-008).
Schedule::command('hub:prune-raw')->daily();

// API call log kept 1 year (D-036).
Schedule::command('hub:prune-api-calls')->daily();

// Stale-feed alert: one email when the feed turns stale, one when it recovers (D-041).
Schedule::command('hub:check-feed')->everyFiveMinutes()->withoutOverlapping(5);

// Served values follow reference-data changes made in the screens (re-apply pending).
Schedule::command('hub:reapply-corrections --if-pending')->everyFiveMinutes()->withoutOverlapping(10);
