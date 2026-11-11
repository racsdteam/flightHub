<?php

namespace App\Providers;

use App\Admin\Accounts;
use App\Admin\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Actions\AttemptToAuthenticate;
use Laravel\Fortify\Actions\CanonicalizeUsername;
use Laravel\Fortify\Actions\PrepareAuthenticatedSession;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Passkeys;

/**
 * Admin sign-in (D-040, D-043): email + password, then TOTP. Fortify's own
 * routes are not registered (routes/web.php holds the few that are used);
 * the account lock replaces Fortify's login throttle.
 */
class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Fortify::ignoreRoutes();
        Passkeys::ignoreRoutes();
    }

    public function boot(): void
    {
        Fortify::loginView(fn () => view('auth.login'));
        Fortify::twoFactorChallengeView(fn () => view('auth.two-factor-challenge'));
        Fortify::authenticateUsing(fn (Request $request) => Accounts::authenticate($request));
        Fortify::authenticateThrough(fn () => [
            CanonicalizeUsername::class,
            RedirectIfTwoFactorAuthenticatable::class,
            AttemptToAuthenticate::class,
            PrepareAuthenticatedSession::class,
        ]);

        Event::listen(Login::class, function (Login $event) {
            $event->user->forceFill([
                'failed_logins' => 0,
                'locked_until' => null,
                'last_login_at' => CarbonImmutable::now('UTC'),
            ])->save();
            Audit::record('signin.success', [], 'user', $event->user->id, actor: $event->user->email, userId: $event->user->id);
        });

        Event::listen(Logout::class, function (Logout $event) {
            if ($event->user !== null) {
                Audit::record('signout', [], 'user', $event->user->id, actor: $event->user->email, userId: $event->user->id);
            }
        });

        Event::listen(TwoFactorAuthenticationFailed::class, fn (TwoFactorAuthenticationFailed $event) => Accounts::failure($event->user, $event->user->email, 'totp'));

        Event::listen(RecoveryCodeReplaced::class, function (RecoveryCodeReplaced $event) {
            Audit::record('totp.recovery_code_used', [], 'user', $event->user->id, actor: $event->user->email, userId: $event->user->id);
        });
    }
}
