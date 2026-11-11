<?php

namespace App\Admin;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;

/**
 * Admin accounts (D-040, D-043): sign-in checks, the account lock, temporary
 * passwords, deactivation. 5 wrong passwords or TOTP codes in a row lock the
 * account for 15 minutes, whatever the IP; the message never says whether
 * the email exists.
 */
final class Accounts
{
    public const ROLES = ['admin', 'steward'];

    public const MAX_FAILURES = 5;

    public const LOCK_MINUTES = 15;

    /** Failed sign-in steps (password or TOTP) per IP per minute before every attempt from it gets 429. */
    public const IP_MAX_FAILURES = 20;

    /**
     * Fortify::authenticateUsing: the user, or null for every refusal alike
     * (unknown email, wrong password, inactive, locked).
     */
    public static function authenticate(Request $request): ?User
    {
        $email = mb_strtolower(trim((string) $request->input('email')));
        $user = User::where('email', $email)->first();

        if ($user !== null && $user->isLocked()) {
            Audit::record('signin.failed', ['email' => mb_substr($email, 0, 100), 'reason' => 'locked'], 'user', $user->id, actor: 'hub');

            return null;
        }

        if ($user !== null && $user->active && Hash::check((string) $request->input('password'), $user->password)) {
            return $user;
        }

        self::failure($user, $email, 'password');

        return null;
    }

    /**
     * One wrong password or TOTP code: counts toward the lock.
     */
    public static function failure(?User $user, string $email, string $kind): void
    {
        Audit::record($kind === 'totp' ? 'totp.failed' : 'signin.failed', ['email' => mb_substr($email, 0, 100)], $user ? 'user' : null, $user?->id, actor: 'hub', userId: $user?->id);

        // Per IP: passwords can be guessed, so the IP is blocked whatever the account.
        $ipKey = self::ipKey();
        RateLimiter::hit($ipKey, 60);
        if (RateLimiter::attempts($ipKey) === self::IP_MAX_FAILURES) {
            Audit::record('signin.ip_blocked', [
                'ip' => request()->ip(),
                'until' => CarbonImmutable::now('UTC')->addSeconds(RateLimiter::availableIn($ipKey))->format('Y-m-d\TH:i:s\Z'),
            ], actor: 'hub');
        }

        if ($user === null) {
            return;
        }

        $failures = $user->failed_logins + 1;
        if ($failures >= self::MAX_FAILURES) {
            $until = CarbonImmutable::now('UTC')->addMinutes(self::LOCK_MINUTES);
            $user->forceFill(['failed_logins' => 0, 'locked_until' => $until])->save();
            Audit::record('account.locked', ['until' => $until->format('Y-m-d\TH:i:s\Z'), 'after' => $kind], 'user', $user->id, actor: 'hub', userId: $user->id);
        } else {
            $user->forceFill(['failed_logins' => $failures])->save();
        }
    }

    public static function ipKey(): string
    {
        return 'signin-ip:'.request()->ip();
    }

    public static function ipBlocked(): bool
    {
        return RateLimiter::tooManyAttempts(self::ipKey(), self::IP_MAX_FAILURES);
    }

    /**
     * Rules for a new password: at least 12 characters, not the email.
     *
     * @return list<string> problems
     */
    public static function passwordProblems(string $password, string $email): array
    {
        $problems = [];
        if (mb_strlen($password) < 12) {
            $problems[] = 'The password must be at least 12 characters.';
        }
        if (mb_strtolower(trim($password)) === mb_strtolower(trim($email))) {
            $problems[] = 'The password must not be your email address.';
        }

        return $problems;
    }

    public static function temporaryPassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $password = '';
        for ($i = 0; $i < 20; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $password;
    }

    /**
     * @return array{user: User, password: string} the temporary password, to be shown once
     */
    public static function create(string $email, string $name, string $role, string $actor): array
    {
        $email = mb_strtolower(trim($email));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || User::where('email', $email)->exists()) {
            throw new InvalidArgumentException('A valid email that is not used yet is required.');
        }
        if (! in_array($role, self::ROLES, true)) {
            throw new InvalidArgumentException('Role must be admin or steward.');
        }

        $password = self::temporaryPassword();
        $user = new User;
        $user->forceFill([
            'name' => mb_substr(trim($name), 0, 255),
            'email' => $email,
            'password' => $password,
            'role' => $role,
            'active' => true,
            'must_change_password' => true,
        ])->save();
        Audit::record('user.created', ['email' => $email, 'role' => $role], 'user', $user->id, actor: $actor);

        return ['user' => $user, 'password' => $password];
    }

    /**
     * New temporary password and a TOTP reset (the last-admin case); unlocks and ends sessions.
     */
    public static function reset(User $user, string $actor): string
    {
        $password = self::temporaryPassword();
        $user->forceFill([
            'password' => $password,
            'must_change_password' => true,
            'failed_logins' => 0,
            'locked_until' => null,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
        self::endSessions($user);
        Audit::record('user.reset', ['email' => $user->email, 'totp' => 'reset'], 'user', $user->id, actor: $actor);

        return $password;
    }

    public static function reactivate(User $user, string $actor): void
    {
        $user->forceFill(['active' => true, 'failed_logins' => 0, 'locked_until' => null])->save();
        Audit::record('user.reactivated', ['email' => $user->email, 'active' => ['from' => false, 'to' => true]], 'user', $user->id, actor: $actor);
    }

    public static function changeRole(User $user, string $role, string $actor): void
    {
        if (! in_array($role, self::ROLES, true)) {
            throw new InvalidArgumentException('Role must be admin or steward.');
        }
        $before = $user->role;
        $user->forceFill(['role' => $role])->save();
        Audit::record('user.role_changed', ['email' => $user->email, 'role' => ['from' => $before, 'to' => $role]], 'user', $user->id, actor: $actor);
    }

    /**
     * Would this change leave no active admin? (demoting or deactivating the last one)
     */
    public static function isLastActiveAdmin(User $user): bool
    {
        return $user->active && $user->role === 'admin'
            && User::where('active', true)->where('role', 'admin')->where('id', '<>', $user->id)->doesntExist();
    }

    public static function deactivate(User $user, string $actor): void
    {
        $user->forceFill(['active' => false])->save();
        self::endSessions($user);
        Audit::record('user.deactivated', ['email' => $user->email, 'active' => ['from' => true, 'to' => false]], 'user', $user->id, actor: $actor);
    }

    /**
     * Ends every database session of the user (the session middleware also
     * signs out an inactive user on its next request).
     */
    public static function endSessions(User $user): void
    {
        DB::table('sessions')->where('user_id', $user->id)->delete();
    }
}
