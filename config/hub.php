<?php

/*
 * Where the hub is served (D-061). Until a subdomain is bought, the hub lives under a path of
 * systems.rac.co.rw (public_html/flighthub → /home/sysrac/flight-hub/public), which it shares
 * with KIA PULSE and three other apps.
 *
 * base_path: FLIGHTHUB_BASE_PATH, empty = served at the domain root; production /flighthub.
 * Normalised: empty, or one leading slash and no trailing slash. It must be APP_URL's path
 * (hub:preflight). Every generated URL comes from APP_URL (AppServiceProvider); routes are matched
 * by Laravel's own base-path detection (SCRIPT_NAME /flighthub/index.php). The move to a subdomain
 * is a config change: FLIGHTHUB_BASE_PATH empty, APP_URL, SESSION_PATH=/.
 */

return [
    'base_path' => rtrim('/'.trim(trim((string) env('FLIGHTHUB_BASE_PATH', '')), '/'), '/'),
];
