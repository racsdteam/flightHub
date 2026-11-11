<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Before any admin page: an inactive user is signed out; a user without a
 * confirmed TOTP is sent to enrolment; then a user flagged
 * must_change_password is sent to change it.
 *
 * account:enrol    — the enrolment pages (active only)
 * account:password — the password change page (active + TOTP)
 * account          — every other page (active + TOTP + password changed)
 */
class EnsureAccountReady
{
    public function handle(Request $request, Closure $next, string $stage = 'ready'): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        if ($stage !== 'enrol' && ! $user->hasConfirmedTotp()) {
            return redirect()->route('two-factor.enrol');
        }

        if ($stage === 'ready' && $user->must_change_password) {
            return redirect()->route('password.change');
        }

        return $next($request);
    }
}
