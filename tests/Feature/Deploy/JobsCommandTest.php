<?php

namespace Tests\Feature\Deploy;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Batch 7e: hub:jobs, the running-jobs wait of deploy.sh. It counts the other connections of the
 * runtime database user to the hub's database (here the test database), and prints only their
 * time and command.
 */
class JobsCommandTest extends TestCase
{
    private const NONE = "hub:jobs: 0 other connections of the runtime database user to the hub's database.";

    /** A child process booting the app as the tests do (APP_ENV=testing: the test database). */
    private function child(string $code, array $env = []): Process
    {
        $none = rtrim(sys_get_temp_dir(), '/').'/hub-jobs-none-'.bin2hex(random_bytes(6));

        return new Process([PHP_BINARY, '-r', 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); '.$code],
            base_path(), $env + ['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => $none.'-config.php', 'APP_ROUTES_CACHE' => $none.'-routes.php']);
    }

    public function test_no_other_connection_exits_0(): void
    {
        $this->assertSame(0, Artisan::call('hub:jobs'));
        $this->assertSame(self::NONE, trim(Artisan::output()));
    }

    public function test_a_second_connection_of_the_runtime_user_is_counted(): void
    {
        config(['database.connections.second' => config('database.connections.mariadb')]);
        DB::connection('second')->select('SELECT 1');

        try {
            $this->assertSame(1, Artisan::call('hub:jobs'));
            $output = Artisan::output();
        } finally {
            DB::purge('second');
        }

        $this->assertSame("hub:jobs: 1 other connection of the runtime database user to the hub's database:\n  time 0 s, command Sleep", trim($output));
        // Closed: none again.
        $this->assertSame(0, Artisan::call('hub:jobs'));
    }

    /**
     * A job in the middle of a query (another process, as a cron poll would be): counted, and only
     * its time and command are printed, never the query text, the user, the host or the database.
     */
    public function test_the_output_never_holds_the_query_text_the_user_the_host_or_the_database(): void
    {
        $job = $this->child('Illuminate\Support\Facades\DB::select("SELECT SLEEP(3) AS sentinel_query_text");');
        $job->start();

        try {
            $output = '';
            // The job's connection runs a prepared statement: command Execute (Query when not prepared).
            for ($tries = 0; $tries < 50 && ! preg_match('/command (Query|Execute)/', $output); $tries++) {
                usleep(100_000);
                $code = Artisan::call('hub:jobs');
                $output = Artisan::output();
            }
        } finally {
            $job->wait();         // the query ends by itself: no connection is left behind
        }

        $this->assertSame(1, $code, $output.' / child: '.$job->getOutput().$job->getErrorOutput());
        $this->assertMatchesRegularExpression("/^hub:jobs: 1 other connection of the runtime database user to the hub's database:\n  time \\d+ s, command (Query|Execute)$/", trim($output));
        foreach (['sentinel', 'SLEEP', 'SELECT', (string) config('database.connections.mariadb.username'), (string) config('database.connections.mariadb.database')] as $secretish) {
            $this->assertStringNotContainsString($secretish, $output);
        }
    }

    public function test_an_unreadable_process_list_exits_2_and_names_nothing(): void
    {
        $run = $this->child('$code = Illuminate\Support\Facades\Artisan::call("hub:jobs"); echo Illuminate\Support\Facades\Artisan::output(); exit($code);', ['DB_PASSWORD' => 'Sentinel-wrong-password']);
        $run->setTimeout(60)->run();

        $this->assertSame(2, $run->getExitCode(), $run->getOutput().$run->getErrorOutput());
        $this->assertSame('hub:jobs: could not read the process list of the runtime database connection.', trim($run->getOutput().$run->getErrorOutput()));
    }
}
