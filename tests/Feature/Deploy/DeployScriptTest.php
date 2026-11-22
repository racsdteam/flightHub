<?php

namespace Tests\Feature\Deploy;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * scripts/deploy.sh (Batch 7, order of D-056), run by bash against stub php, git, curl and
 * composer that log every call (and whether DB_DDL_PASSWORD was in its environment, never
 * its value). A copy of the script carries a test token; the committed script is run as it is
 * for the refusal.
 */
class DeployScriptTest extends TestCase
{
    private const TOKEN = 'yes-2026-11-22-dk';

    private const DDL_PASSWORD = 'Sentinel-DDL-pw-7f3a';

    private const TARGET = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const HEAD = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const PUSHED_LATER = 'cccccccccccccccccccccccccccccccccccccccc';

    private const DOWN = 'artisan down --render=errors::503 --retry=15 --secret=f00df00df00df00df00df00df00df00d ddl=no';

    private const MERGE = 'git merge --ff-only --quiet '.self::TARGET;

    private const COMPOSER = 'php composer install --no-dev --optimize-autoloader --no-interaction ddl=no';

    private const APP_URL = 'https://systems.rac.co.rw/flighthub';

    private static function curl(string $url): string
    {
        return 'curl -sS --max-time 20 -b laravel_maintenance=BYPASSCOOKIE -D - -o /dev/null -w HTTP_CODE=%{http_code} '.$url;
    }

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
                  *'config($argv[1])'*)
                    case "$3" in
                      app.url) printf '%s' "${STUB_APP_URL-https://systems.rac.co.rw/flighthub}" ;;
                      hub.base_path) printf '%s' "${STUB_BASE_PATH-/flighthub}" ;;
                      *) echo "unexpected config key $3" >&2; exit 9 ;;
                    esac ;;
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
                  help) [ -z "${STUB_NO_JOBS_COMMAND:-}" ] ;;
                  hub:jobs)
                    # STUB_JOBS: how many more checks still find a job connected ("forever": always;
                    # "error": the process list cannot be read).
                    n="$(cat "$STUB_DIR/jobs" 2>/dev/null || echo "${STUB_JOBS:-0}")"
                    [ "$n" = error ] && { echo "hub:jobs: could not read the process list of the runtime database connection." >&2; exit 2; }
                    if [ "$n" = forever ] || [ "$n" -gt 0 ]; then
                      [ "$n" = forever ] || echo $((n - 1)) > "$STUB_DIR/jobs"
                      echo "hub:jobs: 1 other connection of the runtime database user to the hub's database:"
                      echo "  time 4 s, command Execute"
                      exit 1
                    fi
                    echo "hub:jobs: 0 other connections of the runtime database user to the hub's database." ;;
                esac ;;
              *maintenance-bypass-cookie.php) echo "php bypass-cookie $2 ddl=$ddl" >> "$STUB_LOG"; printf 'BYPASSCOOKIE' ;;
              *)
                echo "php $(basename "$1") ${*:2} ddl=$ddl" >> "$STUB_LOG"
                [ "${2:-}" = install ] && [ -n "${STUB_COMPOSER_FAIL:-}" ] && exit 1
                exit 0 ;;
            esac
            SH);
        $this->stub('git', <<<'SH'
            #!/bin/bash
            echo "git $*" >> "$STUB_LOG"
            target="aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
            case "$1" in
              remote) echo "${STUB_REMOTE:-github-flighthub:racsdteam/flightHub.git}" ;;
              status) [ -n "${STUB_DIRTY:-}" ] && echo " M app/Collector/Poller.php"; exit 0 ;;
              fetch) exit 0 ;;
              rev-parse)
                last="${@: -1}"
                case "$last" in
                  '@{u}^{commit}')
                    # A push lands right after the first resolution: a second one would see it.
                    if [ -f "$STUB_DIR/pushed" ]; then echo cccccccccccccccccccccccccccccccccccccccc; else touch "$STUB_DIR/pushed"; echo "$target"; fi ;;
                  HEAD) echo bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb ;;
                  HEAD:scripts/deploy.sh) echo 1111111111111111111111111111111111111111 ;;
                  *:scripts/deploy.sh) if [ -n "${STUB_SCRIPT_CHANGED:-}" ]; then echo 2222222222222222222222222222222222222222; else echo 1111111111111111111111111111111111111111; fi ;;
                esac ;;
              merge-base) [ -z "${STUB_NOT_ANCESTOR:-}" ] ;;
              cat-file) [ "$3" = "${target}^{commit}" ] ;;
              show)
                src="${STUB_UPSTREAM:-$FLIGHTHUB_APP_DIR/scripts/deploy.sh}"
                if [ -n "${STUB_SCRIPT_CHANGED:-}" ]; then sed 's/  Deploying /  Deploying [re-run copy] /' "$src"; else cat "$src"; fi ;;
              merge) exit 0 ;;
            esac
            SH);
        // Headers on stdout (-D -), then the -w marker. The root answer sets the two web cookies;
        // STUB_COOKIE replaces the XSRF-TOKEN line, STUB_BARE / STUB_LOCATION answer APP_URL itself.
        $this->stub('curl', <<<'SH'
            #!/bin/bash
            url="${@: -1}"
            echo "curl $*" >> "$STUB_LOG"
            cookies=()
            location=""
            type=""
            case "$url" in
              # Plain HTTP (D-062): Apache's redirect for pages, the API's JSON 403.
              http://*/api/v1/health) code="${STUB_HTTP_HEALTH:-403}"; type="${STUB_HTTP_HEALTH_TYPE-application/json}" ;;
              http://*) code="${STUB_HTTP_ROOT:-301}"; location="${STUB_HTTP_LOCATION-https://${url#http://}}" ;;
              */api/v1/health) code="${STUB_HEALTH:-401}"; type="application/json" ;;
              */)
                code="${STUB_ROOT:-302}"
                cookies=("${STUB_COOKIE-XSRF-TOKEN=Sentinel-cookie-value; expires=Sat, 22 Nov 2026 12:00:00 GMT; Max-Age=1800; path=/flighthub; secure; samesite=strict}"
                  "flighthub_session=Sentinel-cookie-value; expires=Sat, 22 Nov 2026 12:00:00 GMT; Max-Age=1800; path=/flighthub; secure; httponly; samesite=strict") ;;
              *) code="${STUB_BARE:-302}"; location="${STUB_LOCATION:-}" ;;
            esac
            printf 'HTTP/1.1 %s Stub\r\n' "$code"
            [ -n "$location" ] && printf 'Location: %s\r\n' "$location"
            [ -n "$type" ] && printf 'Content-Type: %s\r\n' "$type"
            for c in "${cookies[@]}"; do [ -n "$c" ] && printf 'Set-Cookie: %s\r\n' "$c"; done
            printf '\r\n'
            printf 'HTTP_CODE=%s' "$code"
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
        File::put($this->dir.'/log', '');
        File::delete($this->dir.'/pushed');
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
            'FLIGHTHUB_DEPLOY_REEXEC' => false,
            'FLIGHTHUB_DEPLOY_TARGET' => false,
        ], $stdin);
        $process->setTimeout(60)->run();
        $log = array_values(array_filter(explode("\n", (string) File::get($this->dir.'/log'))));

        return ['code' => (int) $process->getExitCode(), 'out' => $process->getOutput().$process->getErrorOutput(), 'log' => $log];
    }

    private static function index(array $log, string $line): int
    {
        $i = array_search($line, $log, true);
        self::assertNotFalse($i, "not in the log: {$line}\n".implode("\n", $log));

        return $i;
    }

    private static function grep(array $log, string $regex): array
    {
        return array_values(preg_grep($regex, $log));
    }

    private static function assertInOrder(array $log, array $lines): void
    {
        $positions = array_map(fn ($line) => self::index($log, $line), $lines);
        $sorted = $positions;
        sort($sorted);
        self::assertSame($sorted, $positions, implode("\n", $log));
    }

    public function test_the_committed_token_refuses_every_deploy(): void
    {
        $this->assertStringContainsString("\nDEPLOY_AUTHORISATION=\"not-yet-authorised\"\n", File::get(base_path('scripts/deploy.sh')));

        // The commit being deployed carries the committed value: every token is refused.
        foreach ([false, self::TOKEN, 'not-yet-authorised', 'yes'] as $token) {
            $run = $this->deploy(['FLIGHTHUB_DEPLOY_AUTHORIZED' => $token, 'STUB_UPSTREAM' => base_path('scripts/deploy.sh')], script: base_path('scripts/deploy.sh'));

            $this->assertSame(1, $run['code']);
            $this->assertStringContainsString('REFUSING TO DEPLOY: this deploy is not yet authorised (D-049).', $run['out']);
            $this->assertStringContainsString('record of WHO authorised WHICH deploy', $run['out']);
            $this->assertSame([], self::grep($run['log'], '/^(artisan|git merge |php composer install)/'), 'nothing changed');
            $this->assertNotSame([], self::grep($run['log'], '/^git show '.self::TARGET.':scripts\/deploy.sh$/'), 'read from the fetched commit');
        }
    }

    public function test_a_wrong_token_or_a_token_argument_is_refused(): void
    {
        $run = $this->deploy(['FLIGHTHUB_DEPLOY_AUTHORIZED' => 'yes-2026-11-21-dk']);
        $this->assertSame(1, $run['code']);
        $this->assertStringContainsString('not yet authorised', $run['out']);
        $this->assertSame([], self::grep($run['log'], '/^(artisan|git merge )/'));

        // The token in the copy on disk does not count: the commit being deployed decides.
        $upstream = $this->dir.'/upstream-deploy.sh';
        File::put($upstream, str_replace('DEPLOY_AUTHORISATION="'.self::TOKEN.'"', 'DEPLOY_AUTHORISATION="yes-2026-11-23-dk"', File::get($this->dir.'/app/scripts/deploy.sh')));
        $run = $this->deploy(['STUB_UPSTREAM' => $upstream]);
        $this->assertSame(1, $run['code']);
        $this->assertStringContainsString('not yet authorised', $run['out']);

        $run = $this->deploy(args: [self::TOKEN]);
        $this->assertSame(2, $run['code']);
        $this->assertStringContainsString('the token is never an argument', $run['out']);
        $this->assertSame([], $run['log']);

        // FLIGHTHUB_DEPLOY_TARGET belongs to the re-run only.
        $run = $this->deploy(['FLIGHTHUB_DEPLOY_TARGET' => self::PUSHED_LATER, 'FLIGHTHUB_DB_DDL_PASSWORD' => self::DDL_PASSWORD]);
        $this->assertSame(1, $run['code']);
        $this->assertStringContainsString('set by this script for its own re-run only', $run['out']);
    }

    public function test_head_must_be_the_fetched_commit_or_its_ancestor(): void
    {
        $run = $this->deploy(['FLIGHTHUB_DB_DDL_PASSWORD' => self::DDL_PASSWORD, 'STUB_NOT_ANCESTOR' => '1']);

        $this->assertSame(1, $run['code']);
        $this->assertStringContainsString('HEAD (bbbbbbbbbbbb) is not the fetched commit (aaaaaaaaaaaa) or its ancestor', $run['out']);
        $this->assertStringContainsString('Nothing has been changed', $run['out']);
        $this->assertSame([], self::grep($run['log'], '/^(artisan|git merge |php composer install)/'));
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

        foreach (['artisan down', 'git merge ', 'artisan migrate'] as $never) {
            $this->assertSame([], self::grep($run['log'], '/^'.preg_quote($never, '/').'/'), $never);
        }
        $this->assertStringNotContainsString(self::DDL_PASSWORD, $run['out'].implode("\n", $run['log']));
    }

    public function test_a_full_deploy_puts_maintenance_on_before_any_file_changes(): void
    {
        // The password is typed; the operator's shell also exports a stray DB_DDL_PASSWORD, which the script drops.
        $run = $this->deploy(['DB_DDL_PASSWORD' => 'stray-shell-value'], stdin: self::DDL_PASSWORD."\n\n");

        $this->assertSame(0, $run['code'], $run['out']);
        $this->assertStringContainsString('==> Done. Deployed aaaaaaaaaaaa (was bbbbbbbbbbbb).', $run['out']);
        $log = $run['log'];
        self::assertInOrder($log, [
            'artisan hub:preflight --ddl-login ddl=yes',
            self::DOWN,
            self::MERGE,
            self::COMPOSER,
            'artisan config:clear ddl=no',
            'artisan migrate --database=mariadb_ddl --force ddl=yes',
            'artisan hub:check-grants ddl=no',
            'artisan hub:grants-sql ddl=yes',
            'artisan hub:reapply-corrections ddl=no',
            'artisan config:cache ddl=no',
            'artisan view:cache ddl=no',
            'artisan event:cache ddl=no',
            'php bypass-cookie f00df00df00df00df00df00df00df00d ddl=no',
            self::curl(self::APP_URL),
            self::curl(self::APP_URL.'/'),
            self::curl(self::APP_URL.'/api/v1/health'),
            self::curl('http://systems.rac.co.rw/flighthub/'),
            self::curl('http://systems.rac.co.rw/flighthub/api/v1/health'),
            'artisan hub:preflight ddl=yes',
            'artisan up ddl=no',
        ]);
        $this->assertSame('artisan up ddl=no', end($log));
        // Running jobs are checked through the database after down and before the code changes;
        // hub:jobs was found in the code on disk before maintenance.
        self::assertInOrder($log, ['artisan help hub:jobs ddl=no', self::DOWN, 'artisan hub:jobs ddl=no', self::MERGE]);
        $this->assertStringContainsString("No other connection to the hub's database: no job is running.", $run['out']);
        $this->assertSame([], self::grep($log, '/^git pull/'));

        // Caches: cleared, then built; no route cache under /flighthub (D-061); never optimize:clear or cache:clear.
        $caches = self::grep(array_slice($log, self::index($log, 'artisan hub:reapply-corrections ddl=no')), '/^artisan (config|route|view|event):(clear|cache)|^artisan clear-compiled/');
        $this->assertSame(['config:clear', 'route:clear', 'view:clear', 'event:clear', 'clear-compiled', 'config:cache', 'view:cache', 'event:cache'],
            array_map(fn ($l) => explode(' ', $l)[1], $caches));
        $this->assertStringContainsString('No route cache: the hub is served under /flighthub (D-061).', $run['out']);
        $this->assertStringContainsString('No cookie outside /flighthub.', $run['out']);
        $this->assertStringContainsString('http://systems.rac.co.rw/flighthub/ answered 301 to https://systems.rac.co.rw/flighthub/.', $run['out']);
        $this->assertStringContainsString('http://systems.rac.co.rw/flighthub/api/v1/health answered 403 over plain HTTP, as it must.', $run['out']);
        $this->assertStringNotContainsString('Sentinel-cookie-value', $run['out']);
        $this->assertSame([], self::grep($log, '/optimize:clear|artisan cache:clear/'));
        // Only the login check, migrate, grants-sql and the preflight ever saw the DDL password.
        $this->assertSame(['artisan hub:preflight --ddl-login ddl=yes', 'artisan migrate --database=mariadb_ddl --force ddl=yes', 'artisan hub:grants-sql ddl=yes', 'artisan hub:preflight ddl=yes'],
            self::grep($log, '/ddl=yes$/'));

        // The grants file: outside the app, mode 600 in a mode-700 directory.
        $files = glob($this->dir.'/deploy/grants-*.sql');
        $this->assertCount(1, $files);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($files[0])), -4));
        $this->assertSame('0700', substr(sprintf('%o', fileperms($this->dir.'/deploy')), -4));
        $this->assertStringContainsString('GRANT SELECT, INSERT', File::get($files[0]));

        $this->assertStringNotContainsString(self::DDL_PASSWORD, $run['out'].implode("\n", $log).File::get($files[0]));
        $this->assertStringNotContainsString('stray-shell-value', $run['out'].implode("\n", $log));
    }

    public function test_a_push_between_fetch_and_update_does_not_change_what_is_deployed(): void
    {
        $run = $this->deploy(['FLIGHTHUB_DB_DDL_PASSWORD' => self::DDL_PASSWORD], stdin: "\n");

        $this->assertSame(0, $run['code'], $run['out']);
        $this->assertCount(1, self::grep($run['log'], '/^git rev-parse .*@\{u\}/'), 'the upstream is resolved once');
        $this->assertSame([self::MERGE], self::grep($run['log'], '/^git merge /'));
        $this->assertStringNotContainsString(self::PUSHED_LATER, implode("\n", $run['log']).$run['out']);
    }

    public function test_unchanged_grants_skip_the_pause(): void
    {
        touch($this->dir.'/grants-applied');

        $run = $this->deploy(['FLIGHTHUB_DB_DDL_PASSWORD' => self::DDL_PASSWORD]);

        $this->assertSame(0, $run['code'], $run['out']);
        $this->assertStringContainsString('The runtime grants match config/grants.php.', $run['out']);
        $this->assertSame([], self::grep($run['log'], '/hub:grants-sql/'));
        $this->assertDirectoryDoesNotExist($this->dir.'/deploy');
    }

    public function test_the_first_install_updates_the_code_and_vendor_before_the_ddl_login_and_maintenance(): void
    {
        File::deleteDirectory($this->dir.'/app/vendor');

        $run = $this->deploy(['FLIGHTHUB_DB_DDL_PASSWORD' => self::DDL_PASSWORD], stdin: "\n");

        $this->assertSame(0, $run['code'], $run['out']);
        self::assertInOrder($run['log'], [self::MERGE, self::COMPOSER, 'artisan hub:preflight --ddl-login ddl=yes', self::DOWN, 'artisan config:clear ddl=no', 'artisan migrate --database=mariadb_ddl --force ddl=yes']);
        $this->assertCount(1, self::grep($run['log'], '/^git merge /'));
        $this->assertCount(1, self::grep($run['log'], '/^php composer install/'));
        // Nothing runs yet: no running-jobs wait (and no hub:jobs check before maintenance).
        $this->assertSame([], self::grep($run['log'], '/hub:jobs/'));
        $this->assertStringContainsString('First install: no job runs yet (no cron), so there is no running-jobs wait.', $run['out']);
    }

    public function test_a_changed_deploy_script_is_re_run_once_before_maintenance_and_the_ddl_prompt_for_the_same_target(): void
    {
        // One password line and one Enter (grants): a second prompt would read the Enter and refuse.
        $run = $this->deploy(['STUB_SCRIPT_CHANGED' => '1'], stdin: self::DDL_PASSWORD."\n\n");

        $this->assertSame(0, $run['code'], $run['out']);
        $this->assertSame(1, substr_count($run['out'], 'scripts/deploy.sh differs in the commit being deployed: running that version once'));
        $this->assertStringContainsString('Deploying [re-run copy] aaaaaaaaaaaa', $run['out']);
        $this->assertSame(1, substr_count($run['out'], 'DDL password for sysrac_fhddl'));
        $this->assertSame(2, substr_count($run['out'], '==> Preconditions'));

        // Before the re-run: nothing but checks (no artisan at all, so no maintenance and no login).
        $log = $run['log'];
        $second = array_keys(preg_grep('/^git status --porcelain/', $log))[1];
        $this->assertSame([], self::grep(array_slice($log, 0, $second), '/^(artisan|git merge |php composer install)/'));
        // The re-run deploys the same TARGET without resolving the upstream again.
        $this->assertCount(1, self::grep($log, '/^git rev-parse .*@\{u\}/'));
        $this->assertCount(1, self::grep($log, '/^git fetch/'));
        $this->assertNotSame([], self::grep($log, '/^git cat-file -e '.self::TARGET.'\^\{commit\}$/'));
        $this->assertSame([self::MERGE], self::grep($log, '/^git merge /'));
        $this->assertCount(1, self::grep($log, '/^artisan down/'));
        // The copy: in the deploy folder, mode 700.
        $copies = glob($this->dir.'/deploy/deploy-rerun.*');
        $this->assertCount(1, $copies);
        $this->assertSame('0700', substr(sprintf('%o', fileperms($copies[0])), -4));
        $this->assertStringNotContainsString(self::DDL_PASSWORD, $run['out']);
    }

    /**
     * Batch 7e: the wait goes through the database (hub:jobs), every 2 s: it waits while hub:jobs
     * exits 1 and goes on at 0, with no file change before.
     */
    public function test_a_running_job_is_waited_for_through_the_database(): void
    {
        $started = microtime(true);
        $run = $this->deploy(['FLIGHTHUB_DB_DDL_PASSWORD' => self::DDL_PASSWORD, 'STUB_JOBS' => '2', 'FLIGHTHUB_JOB_WAIT_SECONDS' => '10'], stdin: "\n");

        $this->assertSame(0, $run['code'], $run['out']);
        $this->assertStringContainsString("A job is connected to the hub's database: waiting for it (hub:jobs every 2 s, up to 10 s).", $run['out']);
        $this->assertStringContainsString('    time 4 s, command Execute', $run['out']);
        $this->assertCount(3, self::grep($run['log'], '/^artisan hub:jobs/'));
        $this->assertGreaterThanOrEqual(4.0, microtime(true) - $started, 'two waits of 2 s');
        $jobs = array_keys(preg_grep('/^artisan hub:jobs/', $run['log']));
        $this->assertTrue(self::index($run['log'], self::DOWN) < $jobs[0] && end($jobs) < self::index($run['log'], self::MERGE));
    }

    public function test_the_wait_for_jobs_timeout_lifts_maintenance_and_changes_nothing(): void
    {
        $run = $this->deploy(['FLIGHTHUB_DB_DDL_PASSWORD' => self::DDL_PASSWORD, 'STUB_JOBS' => 'forever', 'FLIGHTHUB_JOB_WAIT_SECONDS' => '2']);

        $this->assertSame(1, $run['code']);
        $this->assertStringContainsString('still holds a database connection after 2 s', $run['out']);
        $this->assertStringContainsString('NOTHING has been changed (code and database as before); maintenance has been lifted.', $run['out']);
        self::assertInOrder($run['log'], [self::DOWN, 'artisan hub:jobs ddl=no', 'artisan up ddl=no']);
        $this->assertCount(2, self::grep($run['log'], '/^artisan hub:jobs/'));
        $this->assertSame([], self::grep($run['log'], '/^(git merge |php composer install|artisan migrate|artisan config:clear)/'));
        $this->assertStringNotContainsString('DEPLOY FAILED', $run['out']);
    }

    /**
     * hub:jobs cannot read the process list: as for the timeout, maintenance is lifted at once,
     * because nothing has changed.
     */
    public function test_an_unreadable_process_list_lifts_maintenance_and_changes_nothing(): void
    {
        $run = $this->deploy(['FLIGHTHUB_DB_DDL_PASSWORD' => self::DDL_PASSWORD, 'STUB_JOBS' => 'error']);

        $this->assertSame(1, $run['code']);
        $this->assertStringContainsString("hub:jobs could not read the database's process list (exit 2).", $run['out']);
        $this->assertStringContainsString('NOTHING has been changed', $run['out']);
        self::assertInOrder($run['log'], [self::DOWN, 'artisan hub:jobs ddl=no', 'artisan up ddl=no']);
        $this->assertSame([], self::grep($run['log'], '/^(git merge |php composer install|artisan migrate)/'));
    }

    public function test_a_failed_serving_check_leaves_maintenance_on_and_names_both_commits(): void
    {
        foreach (['STUB_ROOT' => ['405', 'route-cache or DirectoryIndex signature'], 'STUB_BARE' => ['404', 'check the symlink'], 'STUB_HEALTH' => ['200', 'do NOT lift maintenance']] as $variable => [$code, $hint]) {
            $run = $this->deploy(['FLIGHTHUB_DB_DDL_PASSWORD' => self::DDL_PASSWORD, $variable => $code], stdin: "\n");

            $this->assertSame(1, $run['code']);
            $this->assertStringContainsString($hint, $run['out']);
            $this->assertStringContainsString('✗ DEPLOY FAILED during: Serving check', $run['out']);
            $this->assertStringContainsString('THE SITE IS STILL IN MAINTENANCE MODE', $run['out']);
            $this->assertStringContainsString('Previous commit: '.self::HEAD, $run['out']);
            $this->assertStringContainsString('Target commit:   '.self::TARGET, $run['out']);
            $this->assertStringContainsString('Migrations had completed, so the schema is', $run['out']);
            $this->assertSame([], self::grep($run['log'], '/^artisan up/'));
        }
    }

    public function test_a_failure_after_the_update_and_before_migrations_says_how_to_return(): void
    {
        $run = $this->deploy(['FLIGHTHUB_DB_DDL_PASSWORD' => self::DDL_PASSWORD, 'STUB_COMPOSER_FAIL' => '1']);

        $this->assertSame(1, $run['code']);
        $this->assertStringContainsString('✗ DEPLOY FAILED during: Dependencies (production only)', $run['out']);
        $this->assertStringContainsString('the database was NOT touched. To return to the previous commit:', $run['out']);
        $this->assertStringContainsString('git reset --hard '.self::HEAD, $run['out']);
        $this->assertSame([], self::grep($run['log'], '/^artisan (migrate|up)/'));
    }

    public function test_a_failed_migration_says_the_database_was_being_written(): void
    {
        $run = $this->deploy(['FLIGHTHUB_DB_DDL_PASSWORD' => self::DDL_PASSWORD, 'STUB_MIGRATE' => 'fail']);

        $this->assertSame(1, $run['code']);
        $this->assertStringContainsString('✗ DEPLOY FAILED during: Migrations (as the DDL user)', $run['out']);
        $this->assertStringContainsString('AND THE DATABASE WAS BEING WRITTEN WHEN THIS FAILED', $run['out']);
        $this->assertStringContainsString('do NOT return the code to the previous commit', $run['out']);
        $this->assertSame([], self::grep($run['log'], '/^artisan up|^artisan hub:check-grants/'));
    }

    public function test_a_failed_preflight_or_down_never_lifts_maintenance(): void
    {
        $run = $this->deploy(['FLIGHTHUB_DB_DDL_PASSWORD' => self::DDL_PASSWORD, 'STUB_PREFLIGHT' => 'fail'], stdin: "\n");
        $this->assertSame(1, $run['code']);
        $this->assertStringContainsString('✗ DEPLOY FAILED during: Preflight', $run['out']);
        $this->assertSame([], self::grep($run['log'], '/^artisan up/'));

        $run = $this->deploy(['FLIGHTHUB_DB_DDL_PASSWORD' => self::DDL_PASSWORD, 'STUB_DOWN' => 'fail']);
        $this->assertSame(1, $run['code']);
        $this->assertStringContainsString('Could not enter maintenance mode. NOTHING has been changed', $run['out']);
        $this->assertSame([], self::grep($run['log'], '/^(artisan (migrate|up)|git merge )/'));
    }

    /**
     * D1 (D-061): the route cache is cleared on every deploy and built only at a domain root (the
     * subdomain move: FLIGHTHUB_BASE_PATH empty and APP_URL, nothing else).
     */
    public function test_the_route_cache_is_built_only_when_the_base_path_is_empty(): void
    {
        touch($this->dir.'/grants-applied');

        $run = $this->deploy(['FLIGHTHUB_DB_DDL_PASSWORD' => self::DDL_PASSWORD]);
        $this->assertSame(0, $run['code'], $run['out']);
        $this->assertCount(1, self::grep($run['log'], '/^artisan route:clear/'));
        $this->assertSame([], self::grep($run['log'], '/^artisan route:cache/'));

        $run = $this->deploy(['FLIGHTHUB_DB_DDL_PASSWORD' => self::DDL_PASSWORD, 'STUB_BASE_PATH' => '', 'STUB_APP_URL' => 'https://hub.example.test']);
        $this->assertSame(0, $run['code'], $run['out']);
        self::assertInOrder($run['log'], ['artisan route:clear ddl=no', 'artisan config:cache ddl=no', 'artisan route:cache ddl=no', 'artisan view:cache ddl=no']);
        self::assertInOrder($run['log'], [self::curl('https://hub.example.test'), self::curl('https://hub.example.test/'), self::curl('https://hub.example.test/api/v1/health')]);
        $this->assertStringNotContainsString('No route cache', $run['out']);
        // At a domain root every cookie path is the hub's own.
        $run = $this->deploy(['FLIGHTHUB_DB_DDL_PASSWORD' => self::DDL_PASSWORD, 'STUB_BASE_PATH' => '', 'STUB_APP_URL' => 'https://hub.example.test', 'STUB_COOKIE' => 'XSRF-TOKEN=x; path=/']);
        $this->assertSame(0, $run['code'], $run['out']);
    }

    /**
     * D2: APP_URL may answer with the web server's slash redirect to APP_URL/, nothing else.
     */
    public function test_the_serving_check_accepts_only_the_slash_redirect_for_app_url_itself(): void
    {
        touch($this->dir.'/grants-applied');

        $run = $this->deploy(['FLIGHTHUB_DB_DDL_PASSWORD' => self::DDL_PASSWORD, 'STUB_BARE' => '301', 'STUB_LOCATION' => self::APP_URL.'/']);
        $this->assertSame(0, $run['code'], $run['out']);
        $this->assertStringContainsString(self::APP_URL.' answered 301 to '.self::APP_URL.'/ (the web server adds the slash).', $run['out']);

        $run = $this->deploy(['FLIGHTHUB_DB_DDL_PASSWORD' => self::DDL_PASSWORD, 'STUB_BARE' => '301', 'STUB_LOCATION' => 'https://systems.rac.co.rw/']);
        $this->assertSame(1, $run['code']);
        $this->assertStringContainsString('answered 301 to another address', $run['out']);
        $this->assertStringContainsString('✗ DEPLOY FAILED during: Serving check', $run['out']);
        $this->assertSame([], self::grep($run['log'], '/^artisan up/'));
    }

    /**
     * D2: no Set-Cookie with a path outside the base path (KIA PULSE shares the host). The message
     * names the cookie and its path, never its value.
     */
    public function test_a_cookie_outside_the_base_path_fails_the_serving_check(): void
    {
        touch($this->dir.'/grants-applied');

        foreach (['XSRF-TOKEN=Sentinel-cookie-value; path=/; secure' => 'XSRF-TOKEN (path=/)',
            'XSRF-TOKEN=Sentinel-cookie-value; secure; samesite=strict' => 'XSRF-TOKEN (path=none)',
            'XSRF-TOKEN=Sentinel-cookie-value; path=/flighthubx' => 'XSRF-TOKEN (path=/flighthubx)',
            'XSRF-TOKEN=Sentinel-cookie-value; Path=/pulse; secure' => 'XSRF-TOKEN (path=/pulse)'] as $cookie => $named) {
            $run = $this->deploy(['FLIGHTHUB_DB_DDL_PASSWORD' => self::DDL_PASSWORD, 'STUB_COOKIE' => $cookie]);

            $this->assertSame(1, $run['code'], $cookie);
            $this->assertStringContainsString('✗ '.self::APP_URL.'/ set a cookie outside /flighthub: '.$named, $run['out']);
            $this->assertStringContainsString('SESSION_PATH=/flighthub and SESSION_COOKIE=flighthub_session', $run['out']);
            $this->assertStringContainsString('✗ DEPLOY FAILED during: Serving check', $run['out']);
            $this->assertStringNotContainsString('Sentinel-cookie-value', $run['out']);
            $this->assertSame([], self::grep($run['log'], '/^artisan (hub:preflight ddl|up)/'));
        }

        // A path under the base path is the hub's own.
        $run = $this->deploy(['FLIGHTHUB_DB_DDL_PASSWORD' => self::DDL_PASSWORD, 'STUB_COOKIE' => 'XSRF-TOKEN=x; path=/flighthub/quality']);
        $this->assertSame(0, $run['code'], $run['out']);
    }

    /**
     * The cron line's flock file (docs/DEPLOY.md, D-061) is ignored by git: deploy.sh refuses a
     * working tree that is not clean, untracked files included.
     */
    public function test_the_cron_lock_file_keeps_the_working_tree_clean(): void
    {
        $process = new Process(['git', 'check-ignore', '-q', 'storage/framework/schedule.lock'], base_path());
        $process->run();

        $this->assertSame(0, $process->getExitCode(), 'storage/framework/schedule.lock must be git-ignored');
    }

    /**
     * B3 (D-062): over plain HTTP, a page is redirected to the same address over HTTPS and the API
     * answers its own 403 (JSON). Anything else leaves maintenance on.
     */
    public function test_the_serving_check_wants_https_only(): void
    {
        touch($this->dir.'/grants-applied');

        foreach ([
            [['STUB_HTTP_ROOT' => '200'], 'expected 301 to https://systems.rac.co.rw/flighthub/ (HTTPS only, D-062)'],
            [['STUB_HTTP_LOCATION' => 'https://systems.rac.co.rw/'], 'answered 301 to https://systems.rac.co.rw/; expected 301'],
            [['STUB_HTTP_HEALTH' => '301'], 'catches the API: it must leave ^api/ alone'],
            [['STUB_HTTP_HEALTH' => '401'], 'taken as HTTPS or APP_ENV is not production'],
            [['STUB_HTTP_HEALTH_TYPE' => 'text/html; charset=UTF-8'], 'comes from in front of the hub (Imunify360)'],
        ] as [$stub, $hint]) {
            $run = $this->deploy(['FLIGHTHUB_DB_DDL_PASSWORD' => self::DDL_PASSWORD] + $stub);

            $this->assertSame(1, $run['code'], json_encode($stub));
            $this->assertStringContainsString($hint, $run['out'], json_encode($stub));
            $this->assertStringContainsString('✗ DEPLOY FAILED during: Serving check', $run['out']);
            $this->assertSame([], self::grep($run['log'], '/^artisan (hub:preflight ddl|up)/'));
        }

        // An http:// APP_URL never gets that far.
        $run = $this->deploy(['FLIGHTHUB_DB_DDL_PASSWORD' => self::DDL_PASSWORD, 'STUB_APP_URL' => 'http://systems.rac.co.rw/flighthub']);
        $this->assertSame(1, $run['code']);
        $this->assertStringContainsString('is not an https:// address', $run['out']);
        $this->assertSame([], self::grep($run['log'], '/^curl/'));
    }

    /**
     * The wait runs hub:jobs from the code on disk: code without it is refused before maintenance.
     */
    public function test_code_on_disk_without_hub_jobs_is_refused_before_maintenance(): void
    {
        $run = $this->deploy(['FLIGHTHUB_DB_DDL_PASSWORD' => self::DDL_PASSWORD, 'STUB_NO_JOBS_COMMAND' => '1']);

        $this->assertSame(1, $run['code'], $run['out']);
        $this->assertStringContainsString('The code on disk has no hub:jobs (it predates Batch 7e), so the running-jobs wait cannot run.', $run['out']);
        $this->assertStringContainsString('Nothing has been changed', $run['out']);
        $this->assertSame([], self::grep($run['log'], '/^(artisan (down|hub:jobs|migrate)|git merge )/'));
    }

    public function test_a_dirty_tree_a_wrong_remote_or_php_refuse_before_anything(): void
    {
        foreach ([['STUB_DIRTY' => '1'], ['STUB_REMOTE' => 'https://github.com/racsdteam/flightHub.git'], ['STUB_PHP_VERSION' => '8.4']] as $env) {
            $run = $this->deploy($env + ['FLIGHTHUB_DB_DDL_PASSWORD' => self::DDL_PASSWORD]);

            $this->assertSame(1, $run['code'], json_encode($env));
            $this->assertStringContainsString('Nothing has been changed', $run['out']);
            $this->assertSame([], self::grep($run['log'], '/^(git (fetch|merge )|artisan)/'));
        }
    }

    public function test_the_dry_run_prints_the_steps_in_the_new_order_and_runs_nothing(): void
    {
        $run = $this->deploy(['FLIGHTHUB_DEPLOY_AUTHORIZED' => false], args: ['--dry-run'], script: base_path('scripts/deploy.sh'));

        $this->assertSame(0, $run['code']);
        $positions = array_map(fn ($step) => strpos($run['out'], $step), ['Preconditions', '1. Maintenance', '2. Running jobs', '3. Update code', '4. Dependencies',
            '6. Migrations', '7. Grants', '10. Serving check', '11. Preflight', '12. Up', 'First install (no vendor/, nothing live)']);
        $this->assertNotContains(false, $positions);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);
        $this->assertStringContainsString('git merge --ff-only TARGET', $run['out']);
        $this->assertStringContainsString('route:cache only when hub.base_path is empty (never under /flighthub, D-061)', $run['out']);
        $this->assertStringContainsString("from config('app.url')", $run['out']);
        $this->assertSame([], $run['log']);
    }
}
