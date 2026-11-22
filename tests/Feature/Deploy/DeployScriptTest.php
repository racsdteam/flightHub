<?php

namespace Tests\Feature\Deploy;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Part C: scripts/deploy.sh, run by bash against stub php, git, curl and composer that log every
 * call (and whether DB_DDL_PASSWORD was in its environment, never its value). A copy of the
 * script carries a test token; the committed script is run as it is for the refusal.
 */
class DeployScriptTest extends TestCase
{
    private const TOKEN = 'yes-2026-11-22-dk';

    private const DDL_PASSWORD = 'Sentinel-DDL-pw-7f3a';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/hub-deploy-test-'.bin2hex(random_bytes(6));
        File::makeDirectory($this->dir.'/app/scripts', 0755, true);
        File::makeDirectory($this->dir.'/app/vendor', 0755, true);
        File::makeDirectory($this->dir.'/bin', 0755, true);
        File::put($this->dir.'/app/vendor/autoload.php', "<?php\n");
        File::put($this->dir.'/app/scripts/deploy.sh', str_replace('DEPLOY_AUTHORISATION="not-yet-authorised"', 'DEPLOY_AUTHORISATION="'.self::TOKEN.'"', File::get(base_path('scripts/deploy.sh'))));
        File::put($this->dir.'/log', '');
        File::put($this->dir.'/bin/composer', "composer stub\n");

        $this->stub('php', <<<'SH'
            #!/bin/bash
            ddl=no; [ -n "${DB_DDL_PASSWORD:-}" ] && ddl=yes
            case "$1" in
              -r)
                case "$2" in
                  *PHP_MAJOR_VERSION*) printf '%s' "${STUB_PHP_VERSION:-8.3}" ;;
                  *random_bytes*) printf 'f00df00df00df00df00df00df00df00d' ;;
                  *app.url*) printf 'https://flightdata.rac.co.rw' ;;
                  *) echo "unexpected php -r" >&2; exit 9 ;;
                esac ;;
              artisan)
                shift
                echo "artisan $* ddl=$ddl" >> "$STUB_LOG"
                case "$1" in
                  hub:check-grants) [ -f "$STUB_DIR/grants-applied" ] && exit 0; echo "hub:check-grants: polls: missing SELECT" >&2; exit 1 ;;
                  hub:grants-sql) touch "$STUB_DIR/grants-applied"; echo "GRANT SELECT, INSERT ON \`sysrac_flighthub\`.\`polls\` TO 'sysrac_fhapp'@'localhost';" ;;
                  hub:preflight) if [ "${2:-}" = "--ddl-login" ]; then [ "${STUB_DDL_LOGIN:-ok}" = ok ]; exit $?; fi; [ "${STUB_PREFLIGHT:-ok}" = ok ] ;;
                  down) [ "${STUB_DOWN:-ok}" = ok ] ;;
                  migrate) [ "${STUB_MIGRATE:-ok}" = ok ] ;;
                esac ;;
              *maintenance-bypass-cookie.php) echo "php bypass-cookie $2 ddl=$ddl" >> "$STUB_LOG"; printf 'BYPASSCOOKIE' ;;
              *) echo "php $(basename "$1") ${*:2} ddl=$ddl" >> "$STUB_LOG" ;;
            esac
            SH);
        $this->stub('git', <<<'SH'
            #!/bin/bash
            echo "git $*" >> "$STUB_LOG"
            case "$1" in
              remote) echo "${STUB_REMOTE:-github-flighthub:racsdteam/flightHub.git}" ;;
              status) [ -n "${STUB_DIRTY:-}" ] && echo " M app/Collector/Poller.php"; exit 0 ;;
              fetch) exit 0 ;;
              show) cat "${STUB_UPSTREAM:-$FLIGHTHUB_APP_DIR/scripts/deploy.sh}" ;;
              pull)
                if [ -n "${STUB_CHANGE_SCRIPT:-}" ]; then
                  f="$FLIGHTHUB_APP_DIR/scripts/deploy.sh"
                  cp "$f" "$f.new" && echo "# changed by a pull $RANDOM$RANDOM" >> "$f.new" && mv "$f.new" "$f"
                fi ;;
            esac
            SH);
        $this->stub('curl', <<<'SH'
            #!/bin/bash
            url="${@: -1}"
            echo "curl $*" >> "$STUB_LOG"
            case "$url" in
              */api/v1/health) printf '%s' "${STUB_HEALTH:-401}" ;;
              */) printf '%s' "${STUB_ROOT:-302}" ;;
            esac
            SH);
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

    /**
     * @param  array<string, string|false>  $env
     * @return array{code: int, out: string, log: list<string>}
     */
    private function deploy(array $env = [], string $stdin = '', ?string $script = null, array $args = []): array
    {
        $process = new Process(['bash', $script ?? $this->dir.'/app/scripts/deploy.sh', ...$args], $this->dir, $env + [
            'PATH' => $this->dir.'/bin:/usr/bin:/bin',
            'HOME' => $this->dir,
            'STUB_LOG' => $this->dir.'/log',
            'STUB_DIR' => $this->dir,
            'FLIGHTHUB_APP_DIR' => $this->dir.'/app',
            'FLIGHTHUB_DEPLOY_DIR' => $this->dir.'/deploy',
            'FLIGHTHUB_PHP' => $this->dir.'/bin/php',
            'FLIGHTHUB_COMPOSER' => $this->dir.'/bin/composer',
            'FLIGHTHUB_DEPLOY_AUTHORIZED' => self::TOKEN,
            'FLIGHTHUB_DB_DDL_USER' => 'sysrac_fhddl',
        ], $stdin);
        $process->setTimeout(30)->run();
        $log = array_values(array_filter(explode("\n", (string) File::get($this->dir.'/log'))));

        return ['code' => (int) $process->getExitCode(), 'out' => $process->getOutput().$process->getErrorOutput(), 'log' => $log];
    }

    private static function index(array $log, string $line): int
    {
        $i = array_search($line, $log, true);
        self::assertNotFalse($i, "not in the log: {$line}\n".implode("\n", $log));

        return $i;
    }

    public function test_the_committed_token_refuses_every_deploy(): void
    {
        $this->assertStringContainsString("\nDEPLOY_AUTHORISATION=\"not-yet-authorised\"\n", File::get(base_path('scripts/deploy.sh')));

        // The commit being deployed carries the committed value: every token is refused.
        foreach ([false, self::TOKEN, 'not-yet-authorised', 'yes'] as $token) {
            File::put($this->dir.'/log', '');
            $run = $this->deploy(['FLIGHTHUB_DEPLOY_AUTHORIZED' => $token, 'STUB_UPSTREAM' => base_path('scripts/deploy.sh')], script: base_path('scripts/deploy.sh'));

            $this->assertSame(1, $run['code']);
            $this->assertStringContainsString('REFUSING TO DEPLOY: this deploy is not yet authorised (D-049).', $run['out']);
            $this->assertStringContainsString('record of WHO authorised WHICH deploy', $run['out']);
            $this->assertSame([], preg_grep('/^(artisan|git pull|php composer install)/', $run['log']), 'nothing changed');
            $this->assertNotSame([], preg_grep('/^git fetch/', $run['log']), 'checked against the fetched commit');
        }
    }

    public function test_a_wrong_token_or_a_token_argument_is_refused(): void
    {
        $run = $this->deploy(['FLIGHTHUB_DEPLOY_AUTHORIZED' => 'yes-2026-11-21-dk']);
        $this->assertSame(1, $run['code']);
        $this->assertStringContainsString('not yet authorised', $run['out']);
        $this->assertSame([], preg_grep('/^(artisan|git pull)/', $run['log']));

        // The token in the copy on disk does not count: the commit being deployed decides.
        $upstream = $this->dir.'/upstream-deploy.sh';
        File::put($upstream, str_replace('DEPLOY_AUTHORISATION="'.self::TOKEN.'"', 'DEPLOY_AUTHORISATION="yes-2026-11-23-dk"', File::get($this->dir.'/app/scripts/deploy.sh')));
        File::put($this->dir.'/log', '');
        $run = $this->deploy(['STUB_UPSTREAM' => $upstream]);
        $this->assertSame(1, $run['code']);
        $this->assertStringContainsString('not yet authorised', $run['out']);
        File::put($this->dir.'/log', '');

        $run = $this->deploy(args: [self::TOKEN]);
        $this->assertSame(2, $run['code']);
        $this->assertStringContainsString('the token is never an argument', $run['out']);
        $this->assertSame([], $run['log']);
    }

    public function test_the_ddl_credentials_are_required_and_checked_before_maintenance(): void
    {
        $run = $this->deploy(['FLIGHTHUB_DB_DDL_USER' => false]);
        $this->assertSame(1, $run['code']);
        $this->assertStringContainsString('Set FLIGHTHUB_DB_DDL_USER', $run['out']);
        $this->assertStringContainsString('Nothing has been changed', $run['out']);

        $run = $this->deploy(stdin: '');
        $this->assertSame(1, $run['code']);
        $this->assertStringContainsString('No DDL password', $run['out']);

        $run = $this->deploy(['FLIGHTHUB_DB_DDL_PASSWORD' => self::DDL_PASSWORD, 'STUB_DDL_LOGIN' => 'wrong']);
        $this->assertSame(1, $run['code']);
        $this->assertStringContainsString('The DDL login failed', $run['out']);

        foreach (['artisan down', 'git pull', 'artisan migrate'] as $never) {
            $this->assertSame([], preg_grep('/^'.preg_quote($never, '/').'/', $run['log']), $never);
        }
        $this->assertStringNotContainsString(self::DDL_PASSWORD, $run['out'].implode("\n", $run['log']));
    }

    public function test_a_full_deploy_runs_in_order_with_the_ddl_password_only_where_it_is_needed(): void
    {
        // The password is typed; the operator's shell also exports a stray DB_DDL_PASSWORD, which the script drops.
        $run = $this->deploy(['DB_DDL_PASSWORD' => 'stray-shell-value'], stdin: self::DDL_PASSWORD."\n\n");

        $this->assertSame(0, $run['code'], $run['out']);
        $this->assertStringContainsString('==> Done. Deploy complete.', $run['out']);
        $log = $run['log'];
        $order = [
            'artisan hub:preflight --ddl-login ddl=yes',
            'git pull --ff-only',
            'php composer install --no-dev --optimize-autoloader --no-interaction ddl=no',
            'artisan down --render=errors::503 --retry=15 --secret=f00df00df00df00df00df00df00df00d ddl=no',
            'artisan config:clear ddl=no',
            'artisan migrate --database=mariadb_ddl --force ddl=yes',
            'artisan hub:check-grants ddl=no',
            'artisan hub:grants-sql ddl=yes',
            'artisan hub:reapply-corrections ddl=no',
            'artisan config:cache ddl=no',
            'artisan route:cache ddl=no',
            'artisan view:cache ddl=no',
            'artisan event:cache ddl=no',
            'php bypass-cookie f00df00df00df00df00df00df00df00d ddl=no',
            'curl -sS --max-time 20 -b laravel_maintenance=BYPASSCOOKIE -o /dev/null -w %{http_code} https://flightdata.rac.co.rw/',
            'curl -sS --max-time 20 -b laravel_maintenance=BYPASSCOOKIE -o /dev/null -w %{http_code} https://flightdata.rac.co.rw/api/v1/health',
            'artisan hub:preflight ddl=yes',
            'artisan up ddl=no',
        ];
        $positions = array_map(fn ($line) => self::index($log, $line), $order);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, implode("\n", $log));
        $this->assertSame('artisan up ddl=no', end($log));

        // Caches: cleared, then built; never optimize:clear or cache:clear.
        $caches = array_values(preg_grep('/^artisan (config|route|view|event):(clear|cache)|^artisan clear-compiled/', array_slice($log, self::index($log, 'artisan hub:reapply-corrections ddl=no'))));
        $this->assertSame(['config:clear', 'route:clear', 'view:clear', 'event:clear', 'clear-compiled', 'config:cache', 'route:cache', 'view:cache', 'event:cache'],
            array_map(fn ($l) => explode(' ', $l)[1], $caches));
        $this->assertSame([], preg_grep('/optimize:clear|artisan cache:clear/', $log));
        // Only migrate, grants-sql and the preflight (and the login check) ever saw the DDL password.
        $this->assertSame(['artisan hub:preflight --ddl-login ddl=yes', 'artisan migrate --database=mariadb_ddl --force ddl=yes', 'artisan hub:grants-sql ddl=yes', 'artisan hub:preflight ddl=yes'],
            array_values(preg_grep('/ddl=yes$/', $log)));

        // The grants file: outside the app, mode 600 in a mode-700 directory.
        $files = glob($this->dir.'/deploy/grants-*.sql');
        $this->assertCount(1, $files);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($files[0])), -4));
        $this->assertSame('0700', substr(sprintf('%o', fileperms($this->dir.'/deploy')), -4));
        $this->assertStringContainsString('GRANT SELECT, INSERT', File::get($files[0]));

        $this->assertStringNotContainsString(self::DDL_PASSWORD, $run['out'].implode("\n", $log).File::get($files[0]));
        $this->assertStringNotContainsString('stray-shell-value', $run['out'].implode("\n", $log));
    }

    public function test_unchanged_grants_skip_the_pause(): void
    {
        touch($this->dir.'/grants-applied');

        $run = $this->deploy(['FLIGHTHUB_DB_DDL_PASSWORD' => self::DDL_PASSWORD]);

        $this->assertSame(0, $run['code'], $run['out']);
        $this->assertStringContainsString('The runtime grants match config/grants.php.', $run['out']);
        $this->assertSame([], preg_grep('/hub:grants-sql/', $run['log']));
        $this->assertDirectoryDoesNotExist($this->dir.'/deploy');
    }

    public function test_the_first_install_checks_the_ddl_login_after_composer_and_before_maintenance(): void
    {
        File::deleteDirectory($this->dir.'/app/vendor');

        $run = $this->deploy(['FLIGHTHUB_DB_DDL_PASSWORD' => self::DDL_PASSWORD], stdin: "\n");

        $this->assertSame(0, $run['code'], $run['out']);
        $composer = self::index($run['log'], 'php composer install --no-dev --optimize-autoloader --no-interaction ddl=no');
        $login = self::index($run['log'], 'artisan hub:preflight --ddl-login ddl=yes');
        $down = self::index($run['log'], 'artisan down --render=errors::503 --retry=15 --secret=f00df00df00df00df00df00df00df00d ddl=no');
        $this->assertTrue($composer < $login && $login < $down);
    }

    public function test_a_changed_deploy_script_is_re_run_once_with_the_preconditions_again(): void
    {
        // From the environment the first time; the re-run asks again (the password is never exported).
        $run = $this->deploy(['FLIGHTHUB_DB_DDL_PASSWORD' => self::DDL_PASSWORD, 'STUB_CHANGE_SCRIPT' => '1'], stdin: self::DDL_PASSWORD."\n\n");

        $this->assertSame(0, $run['code'], $run['out']);
        $this->assertSame(1, substr_count($run['out'], 're-running the new version once'));
        $this->assertSame(1, substr_count($run['out'], 'changed again after the re-run; continuing with this copy'));
        $this->assertSame(2, substr_count($run['out'], '==> Preconditions'));
        $this->assertCount(2, preg_grep('/^git pull/', $run['log']));
        $this->assertCount(1, preg_grep('/^artisan down/', $run['log']));
        $this->assertStringNotContainsString(self::DDL_PASSWORD, $run['out']);
    }

    public function test_a_failed_serving_check_leaves_maintenance_on_with_a_hint(): void
    {
        foreach (['STUB_ROOT' => ['405', 'route-cache signature'], 'STUB_HEALTH' => ['200', 'do NOT lift maintenance']] as $variable => [$code, $hint]) {
            File::put($this->dir.'/log', '');
            $run = $this->deploy(['FLIGHTHUB_DB_DDL_PASSWORD' => self::DDL_PASSWORD, $variable => $code], stdin: "\n");

            $this->assertSame(1, $run['code']);
            $this->assertStringContainsString($hint, $run['out']);
            $this->assertStringContainsString('✗ DEPLOY FAILED during: Serving check', $run['out']);
            $this->assertStringContainsString('THE SITE IS STILL IN MAINTENANCE MODE', $run['out']);
            $this->assertStringContainsString('Migrations had completed, so the schema is whole.', $run['out']);
            $this->assertSame([], preg_grep('/^artisan up/', $run['log']));
        }
    }

    public function test_a_failed_migration_says_the_database_was_being_written(): void
    {
        $run = $this->deploy(['FLIGHTHUB_DB_DDL_PASSWORD' => self::DDL_PASSWORD, 'STUB_MIGRATE' => 'fail']);

        $this->assertSame(1, $run['code']);
        $this->assertStringContainsString('✗ DEPLOY FAILED during: Migrations (as the DDL user)', $run['out']);
        $this->assertStringContainsString('THE DATABASE WAS BEING WRITTEN WHEN THIS FAILED', $run['out']);
        $this->assertStringContainsString('artisan up', $run['out']);
        $this->assertSame([], preg_grep('/^artisan up|^artisan hub:check-grants/', $run['log']));
    }

    public function test_a_failed_preflight_or_down_never_lifts_maintenance(): void
    {
        $run = $this->deploy(['FLIGHTHUB_DB_DDL_PASSWORD' => self::DDL_PASSWORD, 'STUB_PREFLIGHT' => 'fail'], stdin: "\n");
        $this->assertSame(1, $run['code']);
        $this->assertStringContainsString('✗ DEPLOY FAILED during: Preflight', $run['out']);
        $this->assertSame([], preg_grep('/^artisan up/', $run['log']));

        File::put($this->dir.'/log', '');
        $run = $this->deploy(['FLIGHTHUB_DB_DDL_PASSWORD' => self::DDL_PASSWORD, 'STUB_DOWN' => 'fail']);
        $this->assertSame(1, $run['code']);
        $this->assertStringContainsString('Could not enter maintenance mode. The database has NOT been touched', $run['out']);
        $this->assertSame([], preg_grep('/^artisan (migrate|up)/', $run['log']));
    }

    public function test_a_dirty_tree_a_wrong_remote_or_php_refuse_before_anything(): void
    {
        foreach ([['STUB_DIRTY' => '1'], ['STUB_REMOTE' => 'https://github.com/racsdteam/flightHub.git'], ['STUB_PHP_VERSION' => '8.4']] as $env) {
            File::put($this->dir.'/log', '');
            $run = $this->deploy($env + ['FLIGHTHUB_DB_DDL_PASSWORD' => self::DDL_PASSWORD]);

            $this->assertSame(1, $run['code'], json_encode($env));
            $this->assertStringContainsString('Nothing has been changed', $run['out']);
            $this->assertSame([], preg_grep('/^git pull|^artisan/', $run['log']));
        }
    }

    public function test_the_dry_run_prints_the_steps_and_runs_nothing(): void
    {
        $run = $this->deploy(['FLIGHTHUB_DEPLOY_AUTHORIZED' => false], args: ['--dry-run'], script: base_path('scripts/deploy.sh'));

        $this->assertSame(0, $run['code']);
        foreach (['Preconditions', '1. Pull', '3. Maintenance', '5. Migrations', '6. Grants', '9. Serving check', '10. Preflight', '11. Up'] as $step) {
            $this->assertStringContainsString($step, $run['out']);
        }
        $this->assertSame([], $run['log']);
    }
}
