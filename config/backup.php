<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Backup alert (D-047, D-058)
    |--------------------------------------------------------------------------
    |
    | The folder scripts/backup.sh writes to. hub:check-feed mails the alert
    | recipients when its newest manifest is older than 26 hours (or there is
    | none), and once more when it recovers; hub:preflight warns. Empty = the
    | check is off. Only manifest names are read, never a dump.
    |
    */

    'dir' => env('FLIGHTHUB_BACKUP_DIR', ''),

    'stale_after_hours' => 26,

];
