<?php

namespace Tests\Feature\Deploy;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Part F: scripts/backup.sh and scripts/restore-check.sh against stub mysqldump, mysql and git.
 */
class BackupScriptTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/hub-backup-test-'.bin2hex(random_bytes(6));
        foreach (['bin', 'app', 'home', 'backups'] as $sub) {
            File::makeDirectory($this->dir.'/'.$sub, 0755, true);
        }
        File::put($this->dir.'/log', '');
        foreach (['backup', 'restore'] as $cnf) {
            File::put($this->dir."/home/.flighthub-{$cnf}.cnf", "[client]\nuser=x\npassword=not-a-real-one\n");
            chmod($this->dir."/home/.flighthub-{$cnf}.cnf", 0600);
        }

        $this->stub('mysqldump', <<<'SH'
            #!/bin/bash
            echo "mysqldump $*" >> "$STUB_LOG"
            cat <<'DUMP'
            -- MariaDB dump 10.19  Distrib 10.11.19-MariaDB, for Linux (x86_64)
            --
            -- Host: localhost    Database: sysrac_flighthub
            -- ------------------------------------------------------
            -- Server version	10.11.19-MariaDB
            DROP TABLE IF EXISTS `polls`;
            CREATE TABLE `polls` (
              `id` bigint unsigned NOT NULL AUTO_INCREMENT,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB;
            INSERT INTO `polls` VALUES (1,'INSERT INTO `polls` VALUES (9)');
            INSERT INTO `polls` VALUES (2,'b');
            DROP TABLE IF EXISTS `stands`;
            CREATE TABLE `stands` (`id` int);
            /*!50003 CREATE*/ /*!50017 DEFINER=`sysrac_fhddl`@`localhost`*/ /*!50003 TRIGGER `polls_before_update` BEFORE UPDATE ON `polls` FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'append-only' */;;
            DUMP
            [ -n "${STUB_DUMP_FAIL:-}" ] && exit 2
            exit 0
            SH);
        $this->stub('mysql', <<<'SH'
            #!/bin/bash
            echo "mysql $*" >> "$STUB_LOG"
            for a in "$@"; do
              case "$a" in
                *information_schema.TRIGGERS*) echo "${STUB_TRIGGERS:-1}"; exit 0 ;;
                *'FROM `polls`'*) echo "${STUB_COUNT_POLLS:-2}"; exit 0 ;;
                *'FROM `stands`'*) echo "0"; exit 0 ;;
              esac
            done
            cat > "$STUB_DIR/loaded.sql"
            SH);
        $this->stub('git', "#!/bin/bash\necho 0123456789abcdef0123456789abcdef01234567\n");
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function stub(string $name, string $script): void
    {
        File::put($this->dir.'/bin/'.$name, $script."\n");
        chmod($this->dir.'/bin/'.$name, 0755);
    }

    private function script(string $script, array $args = [], array $env = []): Process
    {
        $process = new Process(['bash', base_path('scripts/'.$script), ...$args], $this->dir, $env + [
            'PATH' => $this->dir.'/bin:/usr/bin:/bin',
            'HOME' => $this->dir.'/home',
            'STUB_LOG' => $this->dir.'/log',
            'STUB_DIR' => $this->dir,
            'FLIGHTHUB_APP_DIR' => $this->dir.'/app',
            'FLIGHTHUB_BACKUP_DIR' => $this->dir.'/flight-hub-backups',
            'FLIGHTHUB_BACKUP_CNF' => $this->dir.'/home/.flighthub-backup.cnf',
            'FLIGHTHUB_RESTORE_CNF' => $this->dir.'/home/.flighthub-restore.cnf',
            'FLIGHTHUB_MYSQLDUMP' => $this->dir.'/bin/mysqldump',
            'FLIGHTHUB_MYSQL' => $this->dir.'/bin/mysql',
        ]);
        $process->setTimeout(30)->run();

        return $process;
    }

    /**
     * @return array<string, string>
     */
    private function manifest(string $file): array
    {
        $out = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
            if (preg_match('/^([a-z_0-9]+): (.*)$/', $line, $m)) {
                $out[$m[1]] = $m[2];
            } elseif (preg_match('/^(table \S+|triggers) (\d+)$/', $line, $m)) {
                $out[$m[1]] = $m[2];
            }
        }

        return $out;
    }

    public function test_a_backup_writes_the_dump_and_its_manifest_with_the_credentials_in_a_file_only(): void
    {
        $run = $this->script('backup.sh');

        $this->assertTrue($run->isSuccessful(), $run->getErrorOutput());
        $dumps = glob($this->dir.'/flight-hub-backups/flighthub-*.sql.gz');
        $this->assertCount(1, $dumps);
        $manifest = $this->manifest(substr($dumps[0], 0, -7).'.manifest');
        $this->assertSame(basename($dumps[0]), $manifest['file']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $manifest['time_utc']);
        $this->assertSame('0123456789abcdef0123456789abcdef01234567', $manifest['git_commit']);
        $this->assertSame('10.11.19-MariaDB', $manifest['mariadb_version']);
        $this->assertSame((string) filesize($dumps[0]), $manifest['size_bytes']);
        $this->assertSame(hash_file('sha256', $dumps[0]), $manifest['sha256']);
        $this->assertSame(['2', '0', '1'], [$manifest['table polls'], $manifest['table stands'], $manifest['triggers']]);
        $this->assertStringContainsString('INSERT INTO `polls` VALUES (2', gzdecode(File::get($dumps[0])));

        $call = trim(File::get($this->dir.'/log'));
        $this->assertStringStartsWith('mysqldump --defaults-extra-file='.$this->dir.'/home/.flighthub-backup.cnf --single-transaction --triggers', $call);
        $this->assertStringEndsWith(' sysrac_flighthub', $call);
        $this->assertDoesNotMatchRegularExpression('/(^| )(-p|--password)/', $call);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($dumps[0])), -4));
        $this->assertSame('0700', substr(sprintf('%o', fileperms($this->dir.'/flight-hub-backups')), -4));
    }

    /**
     * Batch 7d (A1): /home/sysrac/backups belongs to root, so the folder is
     * /home/sysrac/flight-hub-backups, beside the app; created mode 700 when missing.
     */
    public function test_the_folder_is_beside_the_app_and_a_folder_that_cannot_be_created_is_refused(): void
    {
        $this->assertStringContainsString('DIR="${FLIGHTHUB_BACKUP_DIR:-/home/sysrac/flight-hub-backups}"', File::get(base_path('scripts/backup.sh')));
        foreach (['scripts/backup.sh', 'scripts/restore-check.sh', '.env.example'] as $file) {
            $this->assertStringNotContainsString('/home/sysrac/backups/flighthub', File::get(base_path($file)), $file);
        }

        File::makeDirectory($this->dir.'/locked', 0500);
        try {
            $run = $this->script('backup.sh', env: ['FLIGHTHUB_BACKUP_DIR' => $this->dir.'/locked/flight-hub-backups']);
        } finally {
            chmod($this->dir.'/locked', 0700);
        }
        $this->assertSame(1, $run->getExitCode());
        $this->assertStringContainsString('backup.sh: cannot create the backup folder '.$this->dir.'/locked/flight-hub-backups', $run->getErrorOutput());
        $this->assertDirectoryDoesNotExist($this->dir.'/locked/flight-hub-backups');
    }

    public function test_only_the_14_newest_dumps_are_kept(): void
    {
        $dir = $this->dir.'/flight-hub-backups';
        File::makeDirectory($dir, 0700, true);
        for ($day = 1; $day <= 15; $day++) {
            $stamp = sprintf('202610%02dT013000Z', $day);
            File::put("{$dir}/flighthub-{$stamp}.sql.gz", 'old');
            File::put("{$dir}/flighthub-{$stamp}.manifest", 'old');
        }
        File::put("{$dir}/backup.log", 'kept');

        $this->assertTrue($this->script('backup.sh')->isSuccessful());

        $dumps = array_map('basename', glob("{$dir}/flighthub-*.sql.gz"));
        $this->assertCount(14, $dumps);
        $this->assertNotContains('flighthub-20261001T013000Z.sql.gz', $dumps);
        $this->assertNotContains('flighthub-20261002T013000Z.sql.gz', $dumps);
        $this->assertFileDoesNotExist("{$dir}/flighthub-20261002T013000Z.manifest");
        $this->assertFileExists("{$dir}/flighthub-20261003T013000Z.manifest");
        $this->assertFileExists("{$dir}/backup.log");
    }

    public function test_a_failed_dump_leaves_nothing_behind(): void
    {
        $run = $this->script('backup.sh', env: ['STUB_DUMP_FAIL' => '1']);

        $this->assertFalse($run->isSuccessful());
        $this->assertSame([], glob($this->dir.'/flight-hub-backups/flighthub-*'));
    }

    public function test_the_credentials_file_must_be_mode_600_and_outside_the_repository(): void
    {
        chmod($this->dir.'/home/.flighthub-backup.cnf', 0644);
        $run = $this->script('backup.sh');
        $this->assertFalse($run->isSuccessful());
        $this->assertStringContainsString('must be mode 600', $run->getErrorOutput());

        File::copy($this->dir.'/home/.flighthub-backup.cnf', $this->dir.'/app/backup.cnf');
        chmod($this->dir.'/app/backup.cnf', 0600);
        $run = $this->script('backup.sh', env: ['FLIGHTHUB_BACKUP_CNF' => $this->dir.'/app/backup.cnf']);
        $this->assertFalse($run->isSuccessful());
        $this->assertStringContainsString('must be outside the repository', $run->getErrorOutput());
        $this->assertSame('', trim(File::get($this->dir.'/log')), 'mysqldump never ran');
    }

    public function test_the_restore_check_loads_into_the_scratch_database_and_compares_the_counts(): void
    {
        $this->script('backup.sh');
        $dump = glob($this->dir.'/flight-hub-backups/flighthub-*.sql.gz')[0];

        $run = $this->script('restore-check.sh', [$dump]);

        $this->assertTrue($run->isSuccessful(), $run->getErrorOutput().$run->getOutput());
        $this->assertStringContainsString('the restore matches the manifest (2 tables and the triggers)', $run->getOutput());
        $loaded = File::get($this->dir.'/loaded.sql');
        $this->assertStringContainsString('TRIGGER `polls_before_update`', $loaded);
        $this->assertStringNotContainsString('DEFINER=', $loaded);
        $this->assertStringContainsString('mysql --defaults-extra-file='.$this->dir.'/home/.flighthub-restore.cnf sysrac_fhrestore', File::get($this->dir.'/log'));

        $run = $this->script('restore-check.sh', [$dump], ['STUB_COUNT_POLLS' => '1']);
        $this->assertSame(1, $run->getExitCode());
        $this->assertStringContainsString('DIFF  polls', $run->getOutput());
    }

    public function test_the_restore_check_refuses_the_production_database_and_a_damaged_dump(): void
    {
        $this->script('backup.sh');
        $dump = glob($this->dir.'/flight-hub-backups/flighthub-*.sql.gz')[0];

        foreach (['sysrac_flighthub' => 'is the production database', 'sysrac_other' => "must contain 'restore' or 'scratch'"] as $db => $message) {
            $run = $this->script('restore-check.sh', [$dump], ['FLIGHTHUB_RESTORE_DB' => $db]);
            $this->assertSame(1, $run->getExitCode());
            $this->assertStringContainsString($message, $run->getErrorOutput());
        }

        File::append($dump, 'x');
        $run = $this->script('restore-check.sh', [$dump]);
        $this->assertSame(1, $run->getExitCode());
        $this->assertStringContainsString('sha256 differs from its manifest', $run->getErrorOutput());
        $this->assertFileDoesNotExist($this->dir.'/loaded.sql');
    }
}
