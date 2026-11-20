<?php

use App\Api\ApiException;
use App\Api\ApiResponses;
use App\Http\Middleware\EnsureAccountReady;
use App\Http\Middleware\EnsureChallengeNotLocked;
use App\Http\Middleware\RemovePoweredBy;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\ThrottleSignIn;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        // API documentation, outside the web group (no session): /api/docs, /api/openapi.yaml.
        then: fn () => Route::group([], __DIR__.'/../routes/docs.php'),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Every response (web, API, documentation, the fallback 404 of an unknown URL, the
        // maintenance page): security headers (outermost, so an exception rendered by an inner
        // middleware gets them too) and no X-Powered-By.
        $middleware->prepend(SecurityHeaders::class);
        $middleware->append(RemovePoweredBy::class);
        $middleware->alias([
            'account' => EnsureAccountReady::class,
            'challenge.unlocked' => EnsureChallengeNotLocked::class,
            'signin.throttle' => ThrottleSignIn::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The distribution API answers JSON {"error", "message"} only, never a stack trace.
        $exceptions->dontReport(ApiException::class);
        $exceptions->render(function (Throwable $e, Request $request) {
            return $request->is('api', 'api/*') ? ApiResponses::fromThrowable($e) : null;
        });
    })->create();
