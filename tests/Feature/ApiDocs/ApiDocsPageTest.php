<?php

namespace Tests\Feature\ApiDocs;

use App\Http\Controllers\ApiDocsController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * D4: /api/docs and /api/openapi.yaml need no sign-in and no session, contain
 * no outside URL, and Swagger UI is served by the hub.
 */
class ApiDocsPageTest extends TestCase
{
    private const SWAGGER_FILES = ['swagger-ui-bundle.js', 'swagger-ui.css', 'LICENSE', 'NOTICE', 'swagger-ui-bundle.js.LICENSE.txt'];

    public function test_the_docs_page_needs_no_sign_in_and_starts_no_session(): void
    {
        $response = $this->get('/api/docs')->assertOk();

        $this->assertGuest();
        $this->assertSame([], $response->headers->getCookies(), 'no session or XSRF cookie');
        $response->assertSee('id="swagger-ui"', false);
        $response->assertSee('data-spec-url="/api/openapi.yaml"', false);
        $this->assertNotContains('web', Route::getRoutes()->getByName('api.docs')->gatherMiddleware());
        $this->assertNotContains('web', Route::getRoutes()->getByName('api.openapi')->gatherMiddleware());
        $this->assertSame(0, DB::table('api_calls')->count(), 'reading the documentation is not an API call');
    }

    public function test_the_page_loads_only_the_hubs_own_files_and_no_outside_url(): void
    {
        $html = $this->get('/api/docs')->getContent();

        $this->assertDoesNotMatchRegularExpression('#(https?:)?//#', $html, 'no absolute or protocol-relative URL');
        preg_match_all('/<script\b[^>]*>/', $html, $scripts);
        preg_match_all('/<(?:link|script)\b[^>]*(?:href|src)="([^"]+)"/', $html, $assets);
        $this->assertSame(['/css/hub.css', '/vendor/swagger-ui/swagger-ui.css', '/vendor/swagger-ui/swagger-ui-bundle.js', '/js/api-docs.js'], $assets[1]);
        foreach ($assets[1] as $asset) {
            $this->assertFileExists(public_path(ltrim($asset, '/')));
        }
        foreach ($scripts[0] as $tag) {
            $this->assertStringContainsString(' src="', $tag, 'no inline script');
        }
        $this->assertStringNotContainsString('style=', $html, 'no inline style');
        $this->assertDoesNotMatchRegularExpression('/<style\b/', $html);

        foreach (self::SWAGGER_FILES as $file) {
            $this->assertFileExists(public_path('vendor/swagger-ui/'.$file));
        }
        $this->assertStringContainsString('Apache License', (string) file_get_contents(public_path('vendor/swagger-ui/LICENSE')));

        $script = (string) file_get_contents(public_path('js/api-docs.js'));
        $this->assertStringContainsString('persistAuthorization: false', $script);
        $this->assertStringContainsString('validatorUrl: null', $script);
        $this->assertStringContainsString('queryConfigEnabled: false', $script);
        $this->assertStringContainsString('showExtensions: true', $script, 'x-field-group is shown');
        $this->assertDoesNotMatchRegularExpression('#https?://#', $script);
    }

    public function test_the_docs_page_keeps_the_csp(): void
    {
        $csp = (string) $this->get('/api/docs')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'none'", $csp);
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9]+'(;|$)/", $csp);
        $this->assertMatchesRegularExpression("/style-src 'self' 'nonce-[A-Za-z0-9]+'(;|$)/", $csp);
        $this->assertStringContainsString("connect-src 'self'", $csp);
        $this->assertStringNotContainsString('unsafe-eval', $csp);
        $this->assertStringNotContainsString('unsafe-inline', $csp);
    }

    public function test_the_document_is_served_as_committed_with_no_outside_url(): void
    {
        $response = $this->get('/api/openapi.yaml')->assertOk();

        $this->assertGuest();
        $this->assertSame([], $response->headers->getCookies());
        $this->assertStringStartsWith('application/yaml', (string) $response->headers->get('Content-Type'));
        $this->assertSame(file_get_contents(base_path(ApiDocsController::DOCUMENT)), $response->getContent());
        $this->assertNotNull($response->headers->get('Content-Security-Policy'));

        // The only URLs are the hub's own servers.
        preg_match_all('#https?://[^\s\'"]+#', $response->getContent(), $urls);
        $found = array_values(array_unique(array_map(fn ($u) => rtrim($u, '.,)'), $urls[0])));
        sort($found);
        $this->assertSame(['http://127.0.0.1:8000/api/v1', 'https://flightdata.rac.co.rw/api/v1'], $found);
    }

    public function test_the_consumers_screen_links_to_the_docs(): void
    {
        $this->assertStringContainsString("route('api.docs')", (string) file_get_contents(resource_path('views/livewire/consumers/list.blade.php')));
    }
}
