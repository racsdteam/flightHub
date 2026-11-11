<?php

namespace App\Http\Middleware;

use App\Admin\Accounts;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * 20 failed sign-in steps (password or TOTP) from one IP in a minute: every
 * sign-in attempt from that IP gets 429 for the rest of the minute, valid or
 * not (passwords can be guessed, unlike API keys).
 */
class ThrottleSignIn
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Accounts::ipBlocked()) {
            $seconds = max(1, RateLimiter::availableIn(Accounts::ipKey()));

            return response()->view('auth.throttled', ['seconds' => $seconds], 429)->header('Retry-After', (string) $seconds);
        }

        return $next($request);
    }
}
