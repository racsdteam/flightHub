<?php

use App\Api\ApiException;
use App\Api\ApiResponses;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The distribution API answers JSON {"error", "message"} only, never a stack trace.
        $exceptions->dontReport(ApiException::class);
        $exceptions->render(function (Throwable $e, Request $request) {
            return $request->is('api', 'api/*') ? ApiResponses::fromThrowable($e) : null;
        });
    })->create();
