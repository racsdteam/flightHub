<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Collector\PollTestCase;

/**
 * G6: security headers, no outside URL in rendered pages, fonts served locally.
 */
class SecurityHeadersTest extends PollTestCase
{
    use AdminHelpers;

    private function assertHeaders(TestResponse $response): void
    {
        $response->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'same-origin')
            ->assertHeader('Permissions-Policy');
        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringNotContainsString('unsafe-eval', $csp);
        $this->assertStringNotContainsString('unsafe-inline', $csp);
        $this->assertDoesNotMatchRegularExpression('#https?://#', $csp);
    }

    /**
     * Every src / href / url() points to the hub itself.
     */
    private function assertNoOutsideUrl(string $html): void
    {
        preg_match_all('/(?:src|href|action)\s*=\s*"([^"]*)"|url\(\s*[\'"]?([^\'")]+)/i', $html, $m);
        $own = rtrim(url('/'), '/');
        foreach (array_filter([...$m[1], ...$m[2]]) as $target) {
            $this->assertTrue(! preg_match('#^(https?:)?//#i', $target) || str_starts_with($target, $own), "outside URL: {$target}");
        }
    }

    public function test_headers_and_no_outside_url_on_every_screen(): void
    {
        $this->poll();
        $this->assertHeaders($login = $this->get('/login'));
        $this->assertNoOutsideUrl($login->getContent());

        $user = $this->user(totp: false, mustChange: true);
        $this->actingAs($user);
        $enrol = $this->get('/two-factor/enrol');
        $this->assertHeaders($enrol);
        $this->assertNoOutsideUrl($enrol->getContent());

        $admin = $this->user();
        $this->actingAs($admin);
        $dashboard = $this->get('/')->assertOk();
        $this->assertHeaders($dashboard);
        $this->assertNoOutsideUrl($dashboard->getContent());
    }

    public function test_livewire_assets_carry_the_request_nonce_and_use_the_csp_build(): void
    {
        $this->poll();
        $this->actingAs($this->user());

        // One request per test: Livewire renders its assets once per request.
        $dashboard = $this->get('/')->assertOk();

        preg_match("/'nonce-([A-Za-z0-9]+)'/", $dashboard->headers->get('Content-Security-Policy'), $nonce);
        $html = $dashboard->getContent();
        $this->assertMatchesRegularExpression('/<script src="[^"]*livewire\.js\?id=[^"]*-csp" nonce="'.$nonce[1].'"/', $html);
        $this->assertMatchesRegularExpression('/<style nonce="'.$nonce[1].'" data-livewire-style>/', $html);
        $this->assertDoesNotMatchRegularExpression('/<(script|style)(?![^>]*nonce=)(?![^>]*src=)[^>]*>/', $html, 'every inline script or style has the nonce');
        $this->assertDoesNotMatchRegularExpression('/\sstyle="/', $html, 'no inline style attribute');
    }

    public function test_hsts_only_in_production(): void
    {
        $this->get('/login')->assertHeaderMissing('Strict-Transport-Security');

        $this->app['env'] = 'production';
        $this->get('/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_fonts_and_css_are_served_from_the_hub(): void
    {
        $css = (string) file_get_contents(public_path('css/hub.css'));
        $this->assertNoOutsideUrl($css);
        foreach (['ibm-plex-sans-400', 'ibm-plex-sans-500', 'ibm-plex-sans-600', 'ibm-plex-mono-400', 'ibm-plex-mono-500'] as $font) {
            $this->assertStringContainsString("url('../fonts/{$font}.woff2')", $css);
            $this->assertSame('wOF2', substr((string) file_get_contents(public_path("fonts/{$font}.woff2")), 0, 4));
        }
        $this->assertStringContainsString('SIL Open Font License', (string) file_get_contents(public_path('fonts/OFL.txt')));
        $this->assertStringContainsString('href="'.asset('css/hub.css').'"', $this->get('/login')->getContent());
    }

    public function test_session_cookie_settings(): void
    {
        $this->assertSame(30, config('session.lifetime'));
        $this->assertSame('strict', config('session.same_site'));
        $this->assertTrue(config('session.http_only'));
        // phpunit.xml uses the array driver; the application uses database.
        $this->assertStringContainsString("'driver' => env('SESSION_DRIVER', 'database')", (string) file_get_contents(config_path('session.php')));
        $this->assertStringContainsString("SESSION_DRIVER=database\n", (string) file_get_contents(base_path('.env.example')));
    }

    private static function lastLine(string $output): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $output))));

        return (string) end($lines);
    }

    public function test_admin_commands_print_the_temporary_password_once(): void
    {
        Artisan::call('hub:admin-create', ['email' => 'Ops.Admin@RAC.test', 'name' => 'Ops Admin', '--role' => 'steward']);
        // stdout holds the password alone (in tests stderr shares the buffer: last line).
        $output = self::lastLine(Artisan::output());
        $user = User::where('email', 'ops.admin@rac.test')->sole();

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{20}$/', $output);
        $this->assertTrue(Hash::check($output, $user->password));
        $this->assertTrue($user->must_change_password);
        $this->assertSame('steward', $user->role);
        $this->assertSame(1, Artisan::call('hub:admin-create', ['email' => 'ops.admin@rac.test', 'name' => 'Again']));
        $this->assertSame(1, Artisan::call('hub:admin-create', ['email' => 'x@rac.test', 'name' => 'X', '--role' => 'root']));

        $user->forceFill(['two_factor_secret' => encrypt('X'), 'two_factor_confirmed_at' => now(), 'must_change_password' => false, 'locked_until' => now()->addHour()])->save();
        Artisan::call('hub:admin-reset', ['email' => 'ops.admin@rac.test']);
        $reset = self::lastLine(Artisan::output());
        $user->refresh();
        $this->assertTrue(Hash::check($reset, $user->password));
        $this->assertNull($user->two_factor_secret);
        $this->assertTrue($user->must_change_password);
        $this->assertFalse($user->isLocked());
    }
}
