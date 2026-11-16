<?php

namespace Tests\Feature;

use App\Http\Middleware\RemovePoweredBy;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * No response carries X-Powered-By (RemovePoweredBy, global middleware): web pages, the API
 * and the documentation. PHP's own header (expose_php) is removed with header_remove();
 * production also sets expose_php = Off.
 */
class PoweredByTest extends TestCase
{
    public function test_the_middleware_runs_on_every_request(): void
    {
        $this->assertTrue(app(Kernel::class)->hasMiddleware(RemovePoweredBy::class));
    }

    public function test_no_response_carries_x_powered_by(): void
    {
        Route::get('/__powered-by-check', fn () => response('ok')->header('X-Powered-By', 'PHP/8.3'));

        $this->get('/__powered-by-check')->assertOk()->assertHeaderMissing('X-Powered-By');
        foreach (['/login', '/api/docs', '/api/openapi.yaml'] as $uri) {
            $this->get($uri)->assertOk()->assertHeaderMissing('X-Powered-By');
        }
        $this->getJson('/api/v1/health')->assertStatus(401)->assertHeaderMissing('X-Powered-By');
        $this->get('/no-such-page')->assertNotFound()->assertHeaderMissing('X-Powered-By');
    }
}
