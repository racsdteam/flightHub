<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * deploy.sh's running-jobs wait (Batch 7e). cPanel's jailshell does not show cron's processes, so
 * pgrep cannot see a running poll; the database can. This counts the OTHER connections of the
 * runtime database user to the hub's database (information_schema.PROCESSLIST: same user, same
 * database, not this connection). In maintenance mode (down --render) web requests are answered
 * before PHP connects, so what remains are CLI jobs: a poll, a re-apply, a backfill, the heartbeat
 * for a moment.
 *
 * Prints the count and, per connection, only its time and command (never the query text, the host
 * or the user). Exit 0 when there is none, 1 when there are some, 2 when the process list cannot
 * be read. No grant is needed: a user always sees its own threads.
 */
class JobsCommand extends Command
{
    protected $signature = 'hub:jobs';

    protected $description = "Count the other connections of the runtime database user to the hub's database (deploy.sh's running-jobs wait)";

    public function handle(): int
    {
        try {
            $rows = DB::connection('mariadb')->select(
                'SELECT TIME AS seconds, COMMAND AS command FROM information_schema.PROCESSLIST'
                ." WHERE USER = SUBSTRING_INDEX(USER(), '@', 1) AND DB = DATABASE() AND ID <> CONNECTION_ID()"
                .' ORDER BY TIME DESC'
            );
        } catch (Throwable) {
            // The message can name the user or the host: only the fact is printed.
            $this->getOutput()->getErrorStyle()->writeln('hub:jobs: could not read the process list of the runtime database connection.');

            return 2;
        }

        $count = count($rows);
        $this->line("hub:jobs: {$count} other ".Str::plural('connection', $count)." of the runtime database user to the hub's database".($count === 0 ? '.' : ':'));
        foreach ($rows as $row) {
            $command = mb_substr((string) preg_replace('/[^A-Za-z ]/', '', (string) $row->command), 0, 32);
            $this->line(sprintf('  time %d s, command %s', (int) $row->seconds, $command === '' ? 'unknown' : $command));
        }

        return $count === 0 ? self::SUCCESS : self::FAILURE;
    }
}
