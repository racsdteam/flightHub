<?php

namespace App\Deploy;

use App\Api\ApiResponses;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * The production posture, checked rather than remembered (hub:preflight, run by deploy.sh before
 * artisan up). Each result is a check name and pass / warn / FAIL / skipped: never a value.
 */
final class Preflight
{
    public const PASS = 'pass';

    public const WARN = 'warn';

    public const FAIL = 'FAIL';

    public const SKIPPED = 'skipped';

    public const APP_URL = 'https://flightdata.rac.co.rw';

    /** Written every minute by the scheduler (routes/console.php), also in maintenance mode. */
    public const HEARTBEAT_KEY = 'hub.scheduler_heartbeat';

    public const CERTIFICATE_WARN_DAYS = 14;

    public const HEARTBEAT_WARN_MINUTES = 5;

    /** History tables: append-only, each with a BEFORE UPDATE and a BEFORE DELETE trigger. */
    public const APPEND_ONLY = ['polls', 'raw_responses', 'flight_versions', 'change_events', 'quality_flag_events', 'admin_audit', 'api_calls'];

    /** Keys the .env file must give a value (AODB_* only with AODB_CLIENT=http). */
    private const REQUIRED_DB = ['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'];

    private const REQUIRED_AODB = ['AODB_USERNAME', 'AODB_PASSWORD'];

    private const REQUIRED_MAIL = ['MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_FROM_ADDRESS'];

    /** @var list<array{0: string, 1: string}> [status, name] */
    private array $results = [];

    /** @var array{config: string, routes: string}|false|null */
    private array|false|null $fresh = false;

    public function __construct(private readonly Probe $probe) {}

    /**
     * @return list<array{0: string, 1: string}> [status, check name]
     */
    public function run(): array
    {
        $this->results = [];
        $this->php();
        $this->application();
        $this->requiredKeys();
        $this->ddlSecrets();
        $this->database();
        $this->session();
        $this->files();
        $this->certificate();
        $this->caches();
        $this->warnings();
        $this->ddlChecks();

        return $this->results;
    }

    private function php(): void
    {
        $this->check('PHP 8.3.x', fn () => preg_match('/^8\.3\.\d+/', $this->probe->phpVersion()) === 1);
        foreach ($this->probe->requiredExtensions() as $extension) {
            $this->check("PHP extension {$extension}", fn () => $this->probe->extensionLoaded($extension));
        }
    }

    private function application(): void
    {
        $this->check('APP_ENV is production', fn () => app()->environment('production'));
        $this->check('APP_DEBUG is off', fn () => config('app.debug') === false);
        $this->check('APP_KEY is set', fn () => (string) config('app.key') !== '');
        $this->check('APP_URL is '.self::APP_URL, fn () => rtrim((string) config('app.url'), '/') === self::APP_URL);
        $this->check('display_errors is off', fn () => ! self::on($this->probe->ini('display_errors')));
    }

    private function requiredKeys(): void
    {
        $this->check('DB_CONNECTION is mariadb', fn () => config('database.default') === 'mariadb');
        foreach (self::REQUIRED_DB as $key) {
            $this->check("{$key} is set", fn () => $this->probe->envFileHasValue($key));
        }
        $http = config('aodb.client') === 'http';
        $this->check('AODB_CLIENT is http', $http);
        if ($http) {
            foreach (self::REQUIRED_AODB as $key) {
                $this->check("{$key} is set", fn () => $this->probe->envFileHasValue($key));
            }
        }
        $this->check('MAIL_MAILER is smtp', fn () => config('mail.default') === 'smtp');
        foreach (self::REQUIRED_MAIL as $key) {
            $this->check("{$key} is set", fn () => $this->probe->envFileHasValue($key));
        }
    }

    private function ddlSecrets(): void
    {
        $this->check('no DDL password in .env', fn () => ! $this->probe->envFileHasValue('DB_DDL_PASSWORD'));
        $this->check('no DDL password in the config cache', function () {
            $cached = $this->probe->cachedConfig();

            return $cached === null || (string) ($cached['database']['connections']['mariadb_ddl']['password'] ?? '') === '';
        });
    }

    private function database(): void
    {
        $connected = $this->probe->runtimeConnectionWorks();
        $this->check('runtime database connection', $connected);
        $this->check('hub:check-grants', fn () => $connected && $this->probe->grantsMatch());
    }

    private function session(): void
    {
        $this->check('session cookie is Secure', fn () => config('session.secure') === true);
        $this->check('session lifetime is 30 minutes', fn () => (int) config('session.lifetime') === 30);
    }

    private function files(): void
    {
        $this->check('storage/ is writable', fn () => array_product(array_map(
            fn ($path) => (int) $this->probe->writable($path),
            [storage_path(), storage_path('app'), storage_path('framework/cache'), storage_path('framework/sessions'), storage_path('framework/views'), storage_path('logs')],
        )) === 1);
        $this->check('bootstrap/cache/ is writable', fn () => $this->probe->writable(base_path('bootstrap/cache')));
    }

    private function certificate(): void
    {
        $url = parse_url((string) config('app.url'));
        $host = $url['host'] ?? null;
        if (($url['scheme'] ?? null) !== 'https' || ! is_string($host) || $host === '') {
            $this->check('TLS certificate of APP_URL is valid', false);
            $this->results[] = [self::SKIPPED, 'TLS certificate expires in more than '.self::CERTIFICATE_WARN_DAYS.' days'];

            return;
        }
        try {
            $certificate = $this->probe->certificate($host, (int) ($url['port'] ?? 443));
        } catch (Throwable) {
            $certificate = ['valid' => false, 'expires_at' => null];
        }
        $this->check('TLS certificate of APP_URL is valid', $certificate['valid']);
        if (! $certificate['valid']) {
            $this->results[] = [self::SKIPPED, 'TLS certificate expires in more than '.self::CERTIFICATE_WARN_DAYS.' days'];

            return;
        }
        $this->check('TLS certificate expires in more than '.self::CERTIFICATE_WARN_DAYS.' days',
            fn () => $certificate['expires_at'] > CarbonImmutable::now()->addDays(self::CERTIFICATE_WARN_DAYS)->getTimestamp(), self::WARN);
    }

    private function caches(): void
    {
        $this->check('config cache matches .env', function () {
            $cached = $this->probe->cachedConfig();
            if ($cached === null) {
                return true;
            }
            $fresh = $this->fresh();

            return $fresh !== null && hash_equals($fresh['config'], self::configFingerprint($cached));
        });
        $this->check('route cache matches the routes', function () {
            if (! $this->probe->routesCached()) {
                return true;
            }
            $fresh = $this->fresh();

            return $fresh !== null && hash_equals($fresh['routes'], self::routeFingerprint());
        });
    }

    private function warnings(): void
    {
        $this->check('zend.exception_ignore_args is On', fn () => self::on($this->probe->ini('zend.exception_ignore_args')), self::WARN);
        $this->check('opcache is enabled', fn () => $this->probe->extensionLoaded('Zend OPcache') && self::on($this->probe->ini('opcache.enable')), self::WARN);
        $this->check('expose_php is Off', fn () => ! self::on($this->probe->ini('expose_php')), self::WARN);
        $this->check('ALERT_EXTRA_RECIPIENTS is set and valid', function () {
            $entries = array_values(array_filter(array_map('trim', explode(',', (string) config('mail.alert_extra_recipients')))));

            return $entries !== [] && array_filter($entries, fn ($entry) => filter_var($entry, FILTER_VALIDATE_EMAIL) === false) === [];
        }, self::WARN);
        $this->check('an active admin exists', fn () => User::where('active', true)->where('role', 'admin')->exists(), self::WARN);
        $this->check('scheduler heartbeat within '.self::HEARTBEAT_WARN_MINUTES.' minutes', function () {
            $beat = Cache::get(self::HEARTBEAT_KEY);

            return is_string($beat) && CarbonImmutable::parse($beat)->gte(CarbonImmutable::now('UTC')->subMinutes(self::HEARTBEAT_WARN_MINUTES));
        }, self::WARN);
        $this->check('feed is OK (not stale)', fn () => ApiResponses::feed()['feed'] === 'ok', self::WARN);
    }

    private function ddlChecks(): void
    {
        $names = ['no pending migrations', 'every append-only table has both its triggers'];
        if (! $this->probe->environmentHas('DB_DDL_PASSWORD')) {
            foreach ($names as $name) {
                $this->results[] = [self::SKIPPED, $name.' (needs the DDL password in this run\'s environment, as inside deploy.sh)'];
            }

            return;
        }
        $this->probe->useDdlFromEnvironment();
        $this->check($names[0], fn () => $this->probe->pendingMigrations() === 0);
        $this->check($names[1], fn () => $this->probe->tablesMissingTriggers(self::APPEND_ONLY) === []);
    }

    /**
     * @param  bool|Closure(): bool  $ok
     */
    private function check(string $name, bool|Closure $ok, string $otherwise = self::FAIL): void
    {
        try {
            $passed = $ok instanceof Closure ? (bool) $ok() : $ok;
        } catch (Throwable) {
            // The message can carry a value (a host, a query): only the result is kept.
            $passed = false;
        }
        $this->results[] = [$passed ? self::PASS : $otherwise, $name];
    }

    /**
     * @return array{config: string, routes: string}|null
     */
    private function fresh(): ?array
    {
        if ($this->fresh === false) {
            $this->fresh = $this->probe->freshFingerprints();
        }

        return $this->fresh;
    }

    private static function on(string|false $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'on', 'yes', 'true', 'stdout', 'stderr'], true);
    }

    /**
     * A config array's fingerprint, without the DDL credentials (inside deploy.sh they are in the
     * environment of the fresh boot, and never in the cache).
     *
     * @param  array<string, mixed>  $config
     */
    public static function configFingerprint(array $config): string
    {
        unset($config['database']['connections']['mariadb_ddl']['username'], $config['database']['connections']['mariadb_ddl']['password']);

        return hash('sha256', serialize(self::sorted($config)));
    }

    public static function routeFingerprint(): string
    {
        $lines = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $middleware = array_map(fn ($m) => is_string($m) ? $m : get_debug_type($m), $route->middleware());
            $lines[] = implode('|', $route->methods()).' '.$route->uri().' '.($route->getName() ?? '').' '.$route->getActionName().' '.implode(',', $middleware);
        }
        sort($lines);

        return hash('sha256', implode("\n", $lines));
    }

    private static function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        ksort($value);

        return array_map(self::sorted(...), $value);
    }
}
