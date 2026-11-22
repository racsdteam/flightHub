<?php

namespace Tests\Feature\BasePath;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;

/**
 * Batch 7c (D-061): served at https://systems.rac.co.rw/flighthub, the way KIA PULSE is served at
 * /pulse on the same host. Every page (ServedTestCase), the cookies, a real Livewire update over
 * HTTP and the return-to-page path.
 */
class UnderBasePathTest extends ServedTestCase
{
    protected const APP_URL = 'https://systems.rac.co.rw/flighthub';

    /**
     * B2: every cookie the hub sets is at path /flighthub, never / or outside the base path.
     *
     * @return list<string> the cookie names
     */
    private function assertCookiesUnderBasePath(TestResponse $response, string $step): array
    {
        $cookies = $response->headers->getCookies();
        $this->assertNotSame([], $cookies, "{$step}: sets cookies");
        foreach ($cookies as $cookie) {
            $this->assertSame('/flighthub', $cookie->getPath(), "{$step}: {$cookie->getName()}");
        }

        return array_map(fn ($cookie) => $cookie->getName(), $cookies);
    }

    public function test_every_cookie_is_set_at_the_base_path(): void
    {
        $this->poll();
        $admin = $this->user('admin');

        // Sign-in page, then sign-in (password, then TOTP).
        $names = $this->assertCookiesUnderBasePath($this->get($this->at('/login'))->assertOk(), 'sign-in page');
        $this->assertEqualsCanonicalizing(['XSRF-TOKEN', 'flighthub_session'], $names);
        $this->assertCookiesUnderBasePath($this->post($this->at('/login'), ['email' => $admin->email, 'password' => self::PASSWORD])->assertRedirect(), 'password');
        $this->assertCookiesUnderBasePath($this->post($this->at('/two-factor-challenge'), ['code' => $this->totp($admin)])->assertRedirect(self::APP_URL), 'sign-in');
        $this->assertAuthenticatedAs($admin);

        // A Livewire update, through its real endpoint (A6: under /flighthub).
        $page = (string) $this->get($this->at('/quality'))->assertOk()->getContent();
        preg_match('/data-update-uri="([^"]+)"/', $page, $uri);
        preg_match('/wire:snapshot="([^"]+)"/', $page, $snapshot);
        $this->assertSame(self::APP_URL.EndpointResolver::updatePath(), $uri[1]);
        $update = $this->postJson($uri[1], ['components' => [['snapshot' => html_entity_decode($snapshot[1], ENT_QUOTES | ENT_HTML5), 'updates' => ['rule' => 'type_missing'], 'calls' => []]]],
            ['X-Livewire' => 'true'])->assertOk();
        $this->assertNotEmpty($update->json('components.0.snapshot'));
        $this->assertCookiesUnderBasePath($update, 'Livewire update');

        // Sign-out.
        $this->assertCookiesUnderBasePath($this->post($this->at('/logout'))->assertRedirect(), 'sign-out');
        $this->assertGuest();
    }

    /**
     * A5: hub.js sends the browser's path (/flighthub/…); signing in returns there, once — never
     * /flighthub/flighthub.
     */
    public function test_signing_in_after_session_ended_returns_to_the_page_under_the_base_path(): void
    {
        $this->get($this->at('/login?session=ended&return='.rawurlencode('/flighthub/flights?flow=ARR&day=2026-10-29')))->assertOk();
        $this->assertSame(self::APP_URL.'/flights?flow=ARR&day=2026-10-29', session('url.intended'));

        $this->signIn($this->user())->assertRedirect(self::APP_URL.'/flights?flow=ARR&day=2026-10-29');
    }

    public function test_a_return_path_outside_the_base_path_signs_in_to_the_dashboard(): void
    {
        foreach (['/pulse/dashboard', '/', '/flights', '/flighthub/login', '/flighthub/flighthub/flights'] as $return) {
            $this->flushSession();
            $this->get($this->at('/login?session=ended&return='.rawurlencode($return)))->assertOk();
            $this->assertNull(session('url.intended'), $return);
        }

        $this->signIn($this->user())->assertRedirect(self::APP_URL);
    }

    /**
     * B3: the HSTS value stays exactly KIA PULSE's while the hub shares its host.
     */
    public function test_hsts_is_exactly_kia_pulses_value(): void
    {
        $this->app['env'] = 'production';

        $this->get($this->at('/login'))->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        $this->get($this->at('/api/v1/health'))->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    /**
     * The API under the base path: matched by the base-path detection, refused without a key.
     */
    public function test_the_api_answers_under_the_base_path(): void
    {
        $response = $this->get($this->at('/api/v1/health'))->assertUnauthorized();

        $this->assertSame([], $response->headers->getCookies(), 'the API sets no cookie');
        foreach (DB::table('api_calls')->pluck('path') as $path) {
            $this->assertSame('/api/v1/health', $path, 'the logged path is the hub\'s own, without the base path');
        }
    }
}
