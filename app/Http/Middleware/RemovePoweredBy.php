<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * No response says which PHP version runs the hub: X-Powered-By is removed from every web,
 * API and documentation response (global middleware). PHP adds that header itself when
 * expose_php is on; production also sets expose_php = Off.
 */
class RemovePoweredBy
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }
        $response->headers->remove('X-Powered-By');

        return $response;
    }
}
