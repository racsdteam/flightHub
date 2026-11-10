<?php

/*
 * Runs once before the suite. Stops early when the runtime user's grants do
 * not match config/grants.php, so no test runs against wrong privileges.
 * Locally one runtime user holds the dev and the test database (apply-grants.sh),
 * so both are checked.
 */

use App\Database\Grants\GrantComparator;
use App\Database\Grants\GrantRegistry;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = (string) config('database.connections.mariadb.database');

if (! $app->environment('testing') || ! str_ends_with($database, '_test')) {
    fwrite(STDERR, "Tests must run with APP_ENV=testing on a *_test database (.env.testing).\n");
    exit(1);
}

$lines = array_map(
    fn ($row) => (string) array_values((array) $row)[0],
    DB::connection('mariadb')->select('SHOW GRANTS FOR CURRENT_USER()'),
);
$problems = GrantComparator::compare(
    GrantRegistry::fromConfig(),
    [$database, substr($database, 0, -strlen('_test'))],
    $lines,
);

if ($problems !== []) {
    fwrite(STDERR, "Runtime grants do not match config/grants.php: run scripts/local-db/apply-grants.sh\n");
    foreach ($problems as $problem) {
        fwrite(STDERR, "  - {$problem}\n");
    }
    exit(1);
}

// Hand PHP's error handlers back before the tests boot their own applications.
DB::purge('mariadb');
HandleExceptions::flushState();
$app->flush();
