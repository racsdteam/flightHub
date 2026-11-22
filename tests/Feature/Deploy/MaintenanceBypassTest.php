<?php

namespace Tests\Feature\Deploy;

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Foundation\Http\MaintenanceModeBypassCookie;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Part C: the bypass cookie of deploy.sh's serving check (scripts/maintenance-bypass-cookie.php),
 * against the prerendered page (down --render) and the middleware. Maintenance mode is held in
 * memory here: `artisan down` would write storage/framework/down in this checkout.
 */
class MaintenanceBypassTest extends TestCase
{
    private const SECRET = 'f00df00df00df00df00df00df00df00d';

    private function cookie(string $secret = self::SECRET): string
    {
        $process = new Process([PHP_BINARY, base_path('scripts/maintenance-bypass-cookie.php'), $secret], base_path());
        $process->setTimeout(60)->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

        return trim($process->getOutput());
    }

    private function down(): void
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

    public function test_the_script_makes_a_cookie_valid_for_this_secret_only(): void
    {
        $cookie = $this->cookie();

        $this->assertTrue(MaintenanceModeBypassCookie::isValid($cookie, self::SECRET));
        $this->assertFalse(MaintenanceModeBypassCookie::isValid($cookie, 'another-secret-another-secret-00'));

        $process = new Process([PHP_BINARY, base_path('scripts/maintenance-bypass-cookie.php'), 'short'], base_path());
        $process->run();
        $this->assertSame(1, $process->getExitCode());
    }

    public function test_the_prerendered_maintenance_page_lets_the_cookie_through(): void
    {
        $dir = sys_get_temp_dir().'/hub-maintenance-'.bin2hex(random_bytes(6));
        File::makeDirectory($dir);
        File::copy(base_path('vendor/laravel/framework/src/Illuminate/Foundation/Console/stubs/maintenance-mode.stub'), $dir.'/maintenance.php');
        File::put($dir.'/down', json_encode(['template' => 'PRERENDERED 503', 'secret' => self::SECRET, 'status' => 503, 'retry' => 15]));
        $request = fn (?string $cookie) => tap(new Process([PHP_BINARY, '-r',
            '$_SERVER["REQUEST_URI"] = "/"; if ($argv[2] !== "") { $_COOKIE["laravel_maintenance"] = $argv[2]; } require $argv[1]; echo "THROUGH TO THE APPLICATION";',
            '--', $dir.'/maintenance.php', $cookie ?? '']))->run();

        try {
            $this->assertSame('THROUGH TO THE APPLICATION', $request($this->cookie())->getOutput());
            $this->assertSame('PRERENDERED 503', $request(null)->getOutput());
            $this->assertSame('PRERENDERED 503', $request($this->cookie('another0another0another0another0'))->getOutput());
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_the_middleware_lets_the_cookie_through_and_the_503_carries_the_security_headers(): void
    {
        $this->down();
        $cookie = $this->cookie();

        $page = $this->get('/')->assertStatus(503);
        $page->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'same-origin')->assertHeader('Retry-After', '15');
        $this->assertStringContainsString("default-src 'none'", (string) $page->headers->get('Content-Security-Policy'));
        $this->getJson('/api/v1/health')->assertStatus(503);

        $this->withUnencryptedCookie('laravel_maintenance', $cookie);
        $this->get('/')->assertRedirect('/login');
        // get(), not getJson(): a JSON test request sends cookies only withCredentials(); curl always sends it.
        $this->get('/api/v1/health')->assertStatus(401)->assertExactJson(['error' => 'UNAUTHORIZED', 'message' => 'A valid API key is required.']);
    }
}
