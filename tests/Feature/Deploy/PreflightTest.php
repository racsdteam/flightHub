<?php

namespace Tests\Feature\Deploy;

use App\Deploy\Preflight;
use App\Deploy\Probe;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Admin\AdminHelpers;
use Tests\Feature\Collector\PollTestCase;

/**
 * Part B: hub:preflight. A healthy production posture (faked where the test machine differs),
 * then one test per FAIL, the warnings, the skipped DDL checks and "never a value".
 */
class PreflightTest extends PollTestCase
{
    use AdminHelpers;

    private FakeProbe $probe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->poll();                       // feed ok
        $this->user('admin');                // an active admin
        Cache::forever(Preflight::HEARTBEAT_KEY, CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z'));
        $this->app['env'] = 'production';
        config([
            'app.debug' => false,
            'app.url' => Preflight::APP_URL,
            'session.secure' => true,
            'aodb.client' => 'http',
            'mail.default' => 'smtp',
            'mail.alert_extra_recipients' => 'it.mailbox@rac.test',
        ]);
        $this->probe = new FakeProbe;
        $this->app->instance(Probe::class, $this->probe);
    }

    /**
     * @return array<string, string> check name → status
     */
    private function preflight(int $exit): array
    {
        $code = Artisan::call('hub:preflight');
        $output = Artisan::output();
        $this->assertSame($exit, $code, $output);
        preg_match_all('/^  (pass|warn|FAIL|skipped)\s+(.+)$/m', $output, $m, PREG_SET_ORDER);

        return array_column(array_map(fn ($r) => [$r[2], $r[1]], $m), 1, 0);
    }

    public function test_a_healthy_production_posture_passes_every_check(): void
    {
        $results = $this->preflight(0);

        $this->assertSame([], array_keys(array_filter($results, fn ($s) => $s !== 'pass')));
        $this->assertArrayHasKey('PHP extension pdo_mysql', $results);
        $this->assertArrayHasKey('PHP extension mbstring', $results);
        $this->assertArrayHasKey('no pending migrations', $results);
        $this->assertArrayHasKey('every append-only table has both its triggers', $results);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function failures(): array
    {
        $names = [
            'PHP 8.3.x', 'PHP extension mbstring', 'PHP extension pdo_mysql', 'APP_ENV is production', 'APP_DEBUG is off', 'APP_KEY is set',
            'APP_URL is https://flightdata.rac.co.rw', 'display_errors is off', 'DB_CONNECTION is mariadb', 'DB_PASSWORD is set', 'AODB_CLIENT is http',
            'AODB_PASSWORD is set', 'MAIL_MAILER is smtp', 'MAIL_FROM_ADDRESS is set', 'no DDL password in .env', 'no DDL password in the config cache',
            'runtime database connection', 'hub:check-grants', 'session cookie is Secure', 'session lifetime is 30 minutes', 'storage/ is writable',
            'bootstrap/cache/ is writable', 'TLS certificate of APP_URL is valid', 'config cache matches .env', 'route cache matches the routes',
            'no pending migrations', 'every append-only table has both its triggers',
        ];

        return array_combine($names, array_map(fn ($n) => [$n], $names));
    }

    #[DataProvider('failures')]
    public function test_each_fail(string $check): void
    {
        match ($check) {
            'PHP 8.3.x' => $this->probe->php = '8.4.1',
            'PHP extension mbstring' => $this->probe->missing = ['mbstring'],
            'PHP extension pdo_mysql' => $this->probe->missing = ['pdo_mysql'],
            'APP_ENV is production' => $this->app['env'] = 'local',
            'APP_DEBUG is off' => config(['app.debug' => true]),
            'APP_KEY is set' => config(['app.key' => '']),
            'APP_URL is https://flightdata.rac.co.rw' => config(['app.url' => 'https://flightdata.example.org']),
            'display_errors is off' => $this->probe->ini['display_errors'] = 'On',
            'DB_CONNECTION is mariadb' => config(['database.default' => 'sqlite']),
            'DB_PASSWORD is set' => $this->probe->envFile['DB_PASSWORD'] = false,
            'AODB_CLIENT is http' => config(['aodb.client' => 'fixture']),
            'AODB_PASSWORD is set' => $this->probe->envFile['AODB_PASSWORD'] = false,
            'MAIL_MAILER is smtp' => config(['mail.default' => 'log']),
            'MAIL_FROM_ADDRESS is set' => $this->probe->envFile['MAIL_FROM_ADDRESS'] = false,
            'no DDL password in .env' => $this->probe->envFile['DB_DDL_PASSWORD'] = true,
            'no DDL password in the config cache' => $this->probe->cached = ['database' => ['connections' => ['mariadb_ddl' => ['password' => 'frozen']]]],
            'runtime database connection' => $this->probe->connection = false,
            'hub:check-grants' => $this->probe->grants = false,
            'session cookie is Secure' => config(['session.secure' => false]),
            'session lifetime is 30 minutes' => config(['session.lifetime' => 120]),
            'storage/ is writable' => $this->probe->notWritable = [storage_path('logs')],
            'bootstrap/cache/ is writable' => $this->probe->notWritable = [base_path('bootstrap/cache')],
            'TLS certificate of APP_URL is valid' => $this->probe->certificate = ['valid' => false, 'expires_at' => CarbonImmutable::now()->subDay()->getTimestamp()],
            'config cache matches .env' => [$this->probe->cached = ['app' => ['name' => 'old']], $this->probe->fresh = ['config' => Preflight::configFingerprint(['app' => ['name' => 'new']]), 'routes' => '']],
            'route cache matches the routes' => [$this->probe->routes = true, $this->probe->fresh = ['config' => '', 'routes' => hash('sha256', 'other routes')]],
            'no pending migrations' => $this->probe->pending = 1,
            'every append-only table has both its triggers' => $this->probe->missingTriggers = ['change_events'],
        };

        $results = $this->preflight(1);
        // The test's own transaction lives on the default connection: restore it before teardown.
        config(['database.default' => 'mariadb']);

        $this->assertSame('FAIL', $results[$check] ?? null, $check);
    }

    public function test_matching_caches_pass_and_a_cached_ddl_password_is_ignored_by_the_fingerprint(): void
    {
        $cached = ['app' => ['name' => 'hub'], 'database' => ['connections' => ['mariadb_ddl' => ['username' => '', 'password' => '']]]];
        $fresh = ['app' => ['name' => 'hub'], 'database' => ['connections' => ['mariadb_ddl' => ['username' => 'ddl', 'password' => 'in-the-deploy-environment']]]];
        $this->probe->cached = $cached;
        $this->probe->routes = true;
        $this->probe->fresh = ['config' => Preflight::configFingerprint($fresh), 'routes' => Preflight::routeFingerprint()];

        $results = $this->preflight(0);

        $this->assertSame(['pass', 'pass'], [$results['config cache matches .env'], $results['route cache matches the routes']]);
    }

    public function test_warnings_do_not_fail(): void
    {
        $this->probe->certificate = ['valid' => true, 'expires_at' => CarbonImmutable::now()->addDays(10)->getTimestamp()];
        $this->probe->ini = ['display_errors' => '0', 'zend.exception_ignore_args' => 'Off', 'opcache.enable' => '0', 'expose_php' => 'On'];
        config(['mail.alert_extra_recipients' => 'it.mailbox@rac.test, not-an-address']);
        Cache::forever(Preflight::HEARTBEAT_KEY, CarbonImmutable::now('UTC')->subMinutes(6)->format('Y-m-d\TH:i:s\Z'));
        User::query()->update(['active' => false]);
        $this->travel(16)->minutes();

        $results = $this->preflight(0);

        foreach (['TLS certificate expires in more than 14 days', 'zend.exception_ignore_args is On', 'opcache is enabled', 'expose_php is Off',
            'ALERT_EXTRA_RECIPIENTS is set and valid', 'an active admin exists', 'scheduler heartbeat within 5 minutes', 'feed is OK (not stale)'] as $warning) {
            $this->assertSame('warn', $results[$warning] ?? null, $warning);
        }
        config(['mail.alert_extra_recipients' => '']);
        $this->assertSame('warn', $this->preflight(0)['ALERT_EXTRA_RECIPIENTS is set and valid']);
    }

    public function test_ddl_checks_are_skipped_without_the_ddl_password_in_this_runs_environment(): void
    {
        $this->probe->ddlEnvironment = false;

        $results = $this->preflight(0);

        $this->assertSame('skipped', $results["no pending migrations (needs the DDL password in this run's environment, as inside deploy.sh)"]);
        $this->assertSame('skipped', $results["every append-only table has both its triggers (needs the DDL password in this run's environment, as inside deploy.sh)"]);
    }

    public function test_an_http_app_url_fails_the_certificate_without_a_network_call(): void
    {
        config(['app.url' => 'http://localhost:8000']);
        $this->probe->certificate = null;    // a call would throw

        $results = $this->preflight(1);

        $this->assertSame(['FAIL', 'skipped'], [$results['TLS certificate of APP_URL is valid'], $results['TLS certificate expires in more than 14 days']]);
    }

    public function test_the_output_never_holds_a_value(): void
    {
        config(['app.url' => 'https://sentinel-host.example', 'app.key' => 'base64:sentinel-key', 'mail.alert_extra_recipients' => 'sentinel@rac.test,sentinel-bad']);
        $this->probe->php = '8.2.99-sentinel';

        Artisan::call('hub:preflight');
        $output = Artisan::output();

        $this->assertStringNotContainsString('sentinel', $output);
        $this->assertStringNotContainsString('8.2.99', $output);
        foreach (explode("\n", trim($output)) as $line) {
            $this->assertMatchesRegularExpression('/^(hub:preflight: .+|  (pass|warn|FAIL|skipped)\s+.+)$/', $line);
        }
    }

    public function test_ddl_login_checks_only_the_environment_credentials(): void
    {
        $this->assertSame(0, Artisan::call('hub:preflight', ['--ddl-login' => true]));
        $this->assertStringContainsString('pass     DDL login', Artisan::output());

        $this->probe->ddlEnvironment = false;
        $this->assertSame(1, Artisan::call('hub:preflight', ['--ddl-login' => true]));
        $this->assertStringContainsString('FAIL     DDL login', Artisan::output());
    }

    public function test_fingerprint_prints_hashes_only(): void
    {
        Artisan::call('hub:preflight', ['--fingerprint' => true]);

        $this->assertMatchesRegularExpression('/^\{"config":"[0-9a-f]{64}","routes":"[0-9a-f]{64}"\}$/', trim(Artisan::output()));
    }

    public function test_the_required_extensions_come_from_composer_lock_plus_the_hubs_own(): void
    {
        $extensions = (new Probe)->requiredExtensions();

        foreach (['mbstring', 'openssl', 'tokenizer', 'pdo_mysql', 'zlib'] as $extension) {
            $this->assertContains($extension, $extensions);
        }
    }

    public function test_the_scheduler_writes_a_heartbeat_every_minute_even_in_maintenance(): void
    {
        Cache::forget(Preflight::HEARTBEAT_KEY);
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'hub:heartbeat');

        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->evenInMaintenanceMode);
        $event->run($this->app);
        $this->assertSame(CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z'), Cache::get(Preflight::HEARTBEAT_KEY));
    }
}

/**
 * The machine as production would be, unless a test changes one answer. Database answers
 * (connection, grants, DDL checks) are real unless set.
 */
class FakeProbe extends Probe
{
    public string $php = '8.3.33';

    /** @var list<string> */
    public array $missing = [];

    /** @var array<string, string> */
    public array $ini = ['display_errors' => '0', 'zend.exception_ignore_args' => '1', 'opcache.enable' => '1', 'expose_php' => '0'];

    /** @var array<string, bool> */
    public array $envFile = [
        'DB_HOST' => true, 'DB_PORT' => true, 'DB_DATABASE' => true, 'DB_USERNAME' => true, 'DB_PASSWORD' => true,
        'AODB_USERNAME' => true, 'AODB_PASSWORD' => true,
        'MAIL_HOST' => true, 'MAIL_PORT' => true, 'MAIL_USERNAME' => true, 'MAIL_PASSWORD' => true, 'MAIL_FROM_ADDRESS' => true,
        'DB_DDL_PASSWORD' => false,
    ];

    public ?array $cached = null;

    public bool $routes = false;

    public ?array $fresh = null;

    public ?array $certificate;

    public ?bool $connection = null;

    public ?bool $grants = null;

    /** @var list<string> */
    public array $notWritable = [];

    public ?bool $ddlEnvironment = null;

    public ?int $pending = null;

    public ?array $missingTriggers = null;

    public function __construct()
    {
        $this->certificate = ['valid' => true, 'expires_at' => CarbonImmutable::now()->addDays(90)->getTimestamp()];
    }

    public function phpVersion(): string
    {
        return $this->php;
    }

    public function extensionLoaded(string $name): bool
    {
        return ! in_array($name, $this->missing, true);
    }

    public function ini(string $key): string|false
    {
        return $this->ini[$key] ?? false;
    }

    public function writable(string $path): bool
    {
        return ! in_array($path, $this->notWritable, true);
    }

    public function envFileHasValue(string $key): bool
    {
        return $this->envFile[$key] ?? false;
    }

    public function cachedConfig(): ?array
    {
        return $this->cached;
    }

    public function routesCached(): bool
    {
        return $this->routes;
    }

    public function freshFingerprints(): ?array
    {
        return $this->fresh;
    }

    public function certificate(string $host, int $port = 443): array
    {
        return $this->certificate ?? throw new \LogicException('no certificate call expected');
    }

    public function runtimeConnectionWorks(): bool
    {
        return $this->connection ?? parent::runtimeConnectionWorks();
    }

    public function grantsMatch(): bool
    {
        return $this->grants ?? parent::grantsMatch();
    }

    public function environmentHas(string $key): bool
    {
        return str_starts_with($key, 'DB_DDL_') && $this->ddlEnvironment !== null ? $this->ddlEnvironment : parent::environmentHas($key);
    }

    public function pendingMigrations(): ?int
    {
        return $this->pending ?? parent::pendingMigrations();
    }

    public function tablesMissingTriggers(array $tables): ?array
    {
        return $this->missingTriggers ?? parent::tablesMissingTriggers($tables);
    }
}
