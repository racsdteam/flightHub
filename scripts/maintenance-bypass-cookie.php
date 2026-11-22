<?php

/*
 * Prints the value of the maintenance-mode bypass cookie (laravel_maintenance) for the given
 * secret, made by the framework's own MaintenanceModeBypassCookie. deploy.sh uses it for its
 * serving check: the request reaches the real application while everyone else sees the 503 page.
 * The cookie is accepted both by the prerendered page (down --render) and by the middleware,
 * whatever the path, so the deploy never has to visit "/<secret>".
 *
 *   php scripts/maintenance-bypass-cookie.php <secret>
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Http\MaintenanceModeBypassCookie;

$secret = $argv[1] ?? '';
if (! preg_match('/^[A-Za-z0-9]{16,128}$/', $secret)) {
    fwrite(STDERR, "usage: php scripts/maintenance-bypass-cookie.php <secret: 16-128 letters or digits>\n");
    exit(1);
}

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

echo MaintenanceModeBypassCookie::create($secret)->getValue(), "\n";
