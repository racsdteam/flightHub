<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The TOTP challenge of a locked or inactive account is refused with the
 * same message as any failed sign-in.
 */
class EnsureChallengeNotLocked
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->session()->get('login.id');
        $user = $id === null ? null : User::find($id);

        if ($user !== null && ($user->isLocked() || ! $user->active)) {
            $request->session()->forget(['login.id', 'login.remember']);

            return redirect()->route('login')->withErrors(['email' => __('auth.failed')]);
        }

        return $next($request);
    }
}
