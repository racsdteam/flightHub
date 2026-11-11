<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Data-quality thresholds (D-009, D-032)
    |--------------------------------------------------------------------------
    |
    | Quality flags only: delay classification belongs to consumers.
    |
    */

    // No actual after schedule + this many minutes.
    'no_actual_minutes' => (int) (env('QUALITY_NO_ACTUAL_MINUTES') ?: 30),

    // Actual earlier than schedule by more than this many minutes.
    'actual_early_minutes' => (int) (env('QUALITY_ACTUAL_EARLY_MINUTES') ?: 60),

    // Still IN_FLIGHT this many minutes after the estimate.
    'in_flight_stale_minutes' => (int) (env('QUALITY_IN_FLIGHT_STALE_MINUTES') ?: 30),

    // Suspected duplicate: same full flight number and flow, reference times within this many hours.
    'duplicate_window_hours' => (int) (env('QUALITY_DUPLICATE_WINDOW_HOURS') ?: 6),

];
