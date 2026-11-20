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
        $this->assertSame(['/images/favicon-32.png', '/images/apple-touch-icon-180.png', '/css/hub.css', '/vendor/swagger-ui/swagger-ui.css', '/vendor/swagger-ui/swagger-ui-bundle.js', '/js/api-docs.js'], $assets[1]);
        $this->assertStringContainsString('<img src="/images/rac-logo-80.png" width="40" height="40" alt="Rwanda Airports Company">', $html, 'the RAC logo (D-053)');
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
        $this->assertStringContainsString("docExpansion: 'list'", $script, 'operations listed, each collapsed');
        $this->assertStringContainsString('defaultModelsExpandDepth: -1', $script, 'no Schemas section');

        // The version badges and the link to the raw document are hidden in the docs frame only.
        $css = (string) file_get_contents(public_path('css/hub.css'));
        $this->assertStringContainsString(".docs-frame .swagger-ui .info .title small,\n.docs-frame .swagger-ui .info hgroup.main a { display: none; }", $css);
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

    public function test_the_public_document_is_served_with_the_hubs_own_server_only(): void
    {
        $response = $this->get('/api/openapi.yaml')->assertOk();

        $this->assertGuest();
        $this->assertSame([], $response->headers->getCookies());
        $this->assertStringStartsWith('application/yaml', (string) $response->headers->get('Content-Type'));
        $this->assertSame(ApiDocsController::served(), $response->getContent());
        $this->assertStringContainsString('openapi: 3.1.0', $response->getContent());
        // Batch 11b: the CSP is for HTML responses; the document carries the other security headers.
        $this->assertNull($response->headers->get('Content-Security-Policy'));
        $response->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'DENY');

        // The only URL is the hub's own API address; the internal document is not served.
        preg_match_all('#https?://[^\s\'"]+#', $response->getContent(), $urls);
        $this->assertSame([rtrim(config('app.url'), '/').'/api/v1'], array_values(array_unique($urls[0])));
        $this->assertStringNotContainsString('/changes', $response->getContent());
    }

    public function test_the_consumers_screen_links_to_the_docs(): void
    {
        $this->assertStringContainsString("route('api.docs')", (string) file_get_contents(resource_path('views/livewire/consumers/list.blade.php')));
    }
}
