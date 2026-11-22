<?php

namespace Tests\Feature\BasePath;

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Feature\Admin\AdminHelpers;
use Tests\Feature\Collector\PollTestCase;

/**
 * Batch 7c (D-061): the hub served at APP_URL, base path included. The environment is set before
 * the application boots, so AppServiceProvider forces the root URL from it as in production.
 * Requests are made as Apache makes them: the base path in the path, SCRIPT_NAME
 * <base>/index.php (Laravel's own base-path detection), and a Host that is NOT APP_URL's, so a
 * URL taken from the request's Host header shows up as an outside address.
 *
 * Every page: every href, src, action, URL-valued data- attribute (Livewire's included) and CSS
 * url() is under APP_URL (full, or root-absolute under the base path), or #, data: or relative.
 */
abstract class ServedTestCase extends PollTestCase
{
    use AdminHelpers;

    /** APP_URL of the run; the base path is its path. */
    protected const APP_URL = 'https://systems.rac.co.rw/flighthub';

    /** The scheme and Host of the browser's requests: never in a generated URL. */
    protected const REQUEST_HOST = 'http://request-host.test';

    protected const FLIGHT = '8ad28dee-1345-4e86-a9b0-9a0ec98e585a';

    /** @var array<string, array{0: mixed, 1: mixed, 2: string|false}> */
    private array $savedEnv = [];

    protected static function basePath(): string
    {
        return rtrim((string) parse_url(static::APP_URL, PHP_URL_PATH), '/');
    }

    /**
     * FLIGHTHUB_BASE_PATH as an operator might type it (no leading slash, a trailing one):
     * config/hub.php normalises it.
     *
     * @return array<string, string>
     */
    protected function environment(): array
    {
        $base = static::basePath();

        return [
            'APP_URL' => static::APP_URL,
            'FLIGHTHUB_BASE_PATH' => $base === '' ? '' : ltrim($base, '/').'/',
            'SESSION_PATH' => $base === '' ? '/' : $base,
            'SESSION_COOKIE' => 'flighthub_session',
        ];
    }

    public function createApplication()
    {
        foreach ($this->environment() as $key => $value) {
            $this->savedEnv[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null, getenv($key)];
            $_SERVER[$key] = $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }
        // The process-wide env repository treats what it loaded from .env.testing as its own and
        // would load it again over these values: a fresh one keeps them (Env::enablePutenv resets it).
        Env::enablePutenv();

        return parent::createApplication();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $base = static::basePath();
        $this->withServerVariables(['SCRIPT_NAME' => $base.'/index.php', 'PHP_SELF' => $base.'/index.php', 'SCRIPT_FILENAME' => public_path('index.php')]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach ($this->savedEnv as $key => [$server, $env, $process]) {
            if ($server === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $server;
            }
            if ($env === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $env;
            }
            putenv($process === false ? $key : "{$key}={$process}");
        }
        Env::enablePutenv();
    }

    /**
     * A full URL is sent as the browser sends it: the test client would trim the trailing slash
     * of /flighthub/, which Apache serves through DirectoryIndex (the bare /flighthub gets the web
     * server's 301 to it before PHP runs).
     */
    protected function prepareUrlForRequest($uri)
    {
        return is_string($uri) && preg_match('#^https?://#', $uri) ? $uri : parent::prepareUrlForRequest($uri);
    }

    /** The browser's request for a path of the hub. */
    protected function at(string $path): string
    {
        return static::REQUEST_HOST.static::basePath().$path;
    }

    /**
     * @return list<string> every URL-valued attribute and CSS url() of the page
     */
    protected static function urls(string $html): array
    {
        $urls = [];
        preg_match_all('/\s(href|src|action|formaction|srcset|poster|data-[a-z-]+)\s*=\s*"([^"]*)"/i', $html, $attributes, PREG_SET_ORDER);
        foreach ($attributes as [, $name, $value]) {
            $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5);
            // A data- attribute counts when its value is an address (a path or a URL).
            if (str_starts_with(strtolower($name), 'data-') && ! preg_match('#^(/|[a-z][a-z0-9+.-]*:)#i', $value)) {
                continue;
            }
            $urls[] = $value;
        }
        preg_match_all('/url\(\s*[\'"]?([^\'")]+)/i', $html, $css);

        return [...$urls, ...array_map('trim', $css[1])];
    }

    protected function assertOwnUrls(TestResponse|string $page, string $name, int $atLeast = 3): void
    {
        $html = is_string($page) ? $page : (string) $page->getContent();
        $urls = self::urls($html);
        $this->assertGreaterThanOrEqual($atLeast, count($urls), "{$name}: URLs found");
        $base = static::basePath();
        foreach ($urls as $url) {
            $ok = match (true) {
                $url === '', str_starts_with($url, '#'), str_starts_with($url, 'data:') => true,
                str_starts_with($url, '//') => false,
                (bool) preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) => $url === static::APP_URL || str_starts_with($url, static::APP_URL.'/') || str_starts_with($url, static::APP_URL.'?'),
                str_starts_with($url, '/') => $base === '' || str_starts_with($url, $base.'/'),
                default => true,       // relative
            };
            $this->assertTrue($ok, "{$name}: {$url} is not under ".static::APP_URL);
            if ($base !== '') {
                $this->assertStringNotContainsString($base.$base.'/', $url, "{$name}: the base path doubled");
            }
        }
        $this->assertStringNotContainsString('request-host.test', $html, "{$name}: a URL from the request's Host header");
    }

    protected function consumer(): void
    {
        DB::table('consumers')->insert(['code' => 'kia-pulse', 'name' => 'KIA PULSE', 'field_groups' => json_encode(['route']), 'created_by' => 'TEST']);
    }

    /** Maintenance mode held in memory (artisan down would write storage/framework/down). */
    protected function down(): void
    {
        $this->app->instance(MaintenanceMode::class, new class implements MaintenanceMode
        {
            public function activate(array $payload): void {}

            public function deactivate(): void {}

            public function active(): bool
            {
                return true;
            }

            public function data(): array
            {
                return ['secret' => 'f00df00df00df00df00df00df00df00d', 'status' => 503, 'retry' => 15, 'refresh' => null, 'redirect' => null, 'except' => []];
            }
        });
    }

    /**
     * E1: every page, signed out and signed in, under APP_URL.
     */
    public function test_every_page_links_only_under_app_url(): void
    {
        $this->assertSame(static::basePath(), config('hub.base_path'), 'FLIGHTHUB_BASE_PATH normalised');
        $this->poll();
        $this->consumer();
        $flag = DB::table('quality_flags')->orderBy('id')->value('id');

        // Signed out: sign-in, API docs, an unknown address.
        $this->assertOwnUrls($this->get($this->at('/login'))->assertOk()->assertSee('Sign in'), 'sign-in');
        $this->assertOwnUrls($this->get($this->at('/api/docs'))->assertOk(), 'API docs');
        $this->assertOwnUrls($this->get($this->at('/nowhere'))->assertNotFound(), '404');

        // The two-factor step, after the password.
        $admin = $this->user('admin');
        $this->post($this->at('/login'), ['email' => $admin->email, 'password' => self::PASSWORD])->assertRedirect(static::APP_URL.'/two-factor-challenge');
        $this->assertOwnUrls($this->get($this->at('/two-factor-challenge'))->assertOk(), 'two-factor');
        $this->flushSession();

        // Enrolment and the forced password change.
        $this->actingAs($this->user('steward', totp: false));
        $this->assertOwnUrls($this->get($this->at('/two-factor/enrol'))->assertOk(), 'enrol');
        $this->actingAs($this->user('steward', mustChange: true));
        $this->assertOwnUrls($this->get($this->at('/password'))->assertOk(), 'password');

        // Every screen, as an admin.
        $this->actingAs($admin);
        foreach (['/', '/flights', '/flights/'.self::FLIGHT, '/quality', '/quality/'.$flag, '/reference/airport-codes', '/reference/aircraft-types',
            '/reference/stands', '/consumers', '/consumers/kia-pulse', '/consumers/kia-pulse/usage', '/users', '/audit'] as $path) {
            Livewire::flushState();         // Livewire prints its script once per application instance
            $page = $this->get($this->at($path))->assertOk();
            $this->assertOwnUrls($page, $path, 8);
            // Livewire's own addresses (A6): its script and its update endpoint, under APP_URL.
            $html = (string) $page->getContent();
            $this->assertMatchesRegularExpression('#<script src="'.preg_quote(static::APP_URL, '#').'/livewire-[0-9a-f]{8}/livewire[a-z.]*\.js\?id=[^"]+"#', $html, $path);
            $this->assertMatchesRegularExpression('#data-update-uri="'.preg_quote(static::APP_URL, '#').'/livewire-[0-9a-f]{8}/update"#', $html, $path);
        }

        // The 503 page (maintenance).
        $this->down();
        $this->assertOwnUrls($this->get($this->at('/'))->assertStatus(503)->assertSee('Down for maintenance'), '503');
    }
}
