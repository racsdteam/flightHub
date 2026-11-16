<?php

/*
|--------------------------------------------------------------------------
| CORS: off
|--------------------------------------------------------------------------
|
| No path is shared with other origins: no response carries
| Access-Control-Allow-Origin, so a web page on another origin cannot call the
| API from a browser even with a key. Consumers call the API server to server;
| /api/docs calls it from the same origin (tests/Feature/Api/CorsTest).
|
*/

return [

    'paths' => [],

    'allowed_methods' => [],

    'allowed_origins' => [],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
