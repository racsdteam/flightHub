<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers on EVERY response (global middleware, outermost): admin screens, sign-in,
 * the fallback 404 of an unknown URL, the distribution API, /api/docs and /api/openapi.yaml.
 * nosniff, X-Frame-Options, Referrer-Policy and Permissions-Policy everywhere; HSTS in
 * production. The CSP goes on HTML responses: it allows only the hub's own origin plus a
 * per-request nonce for the inline <style>/<script> that Livewire injects; no 'unsafe-eval'
 * (Livewire runs its CSP-safe build), no 'unsafe-inline'.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Str::random(32);
        // Livewire reads the nonce from Vite (no Vite build is used).
        Vite::useCspNonce($nonce);
        app()->instance('csp-nonce', $nonce);

        $response = $next($request);

        if (self::isHtml($response)) {
            $response->headers->set('Content-Security-Policy', implode('; ', [
                "default-src 'none'",
                "script-src 'self' 'nonce-{$nonce}'",
                "style-src 'self' 'nonce-{$nonce}'",
                "img-src 'self' data:",
                "font-src 'self'",
                "connect-src 'self'",
                "form-action 'self'",
                "frame-ancestors 'none'",
                "base-uri 'none'",
            ]));
        }
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        if (app()->environment('production')) {
            // Exactly KIA PULSE's value: the hub shares systems.rac.co.rw with it (D-061) and must
            // not send a different HSTS policy for the same host.
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    /**
     * HTML, or no Content-Type yet (an error page rendered by the exception handler gets
     * PHP's default text/html when it is sent).
     */
    private static function isHtml(Response $response): bool
    {
        $type = $response->headers->get('Content-Type');

        return $type === null || str_starts_with(strtolower($type), 'text/html');
    }
}
