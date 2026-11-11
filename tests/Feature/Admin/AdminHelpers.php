<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;

/**
 * Admin accounts for tests. TOTP codes come from the real Google2FA clock
 * (Fortify verifies against time(), not the test clock).
 */
trait AdminHelpers
{
    protected const PASSWORD = 'correct-horse-battery-staple';

    protected function user(string $role = 'admin', bool $totp = true, bool $mustChange = false, bool $active = true, ?string $email = null): User
    {
        $user = new User;
        $user->forceFill([
            'name' => ucfirst($role).' Tester',
            'email' => $email ?? $role.'.'.bin2hex(random_bytes(3)).'@rac.test',
            'password' => self::PASSWORD,
            'role' => $role,
            'active' => $active,
            'must_change_password' => $mustChange,
        ])->save();

        if ($totp) {
            $user->forceFill([
                'two_factor_secret' => encrypt(app(Google2FA::class)->generateSecretKey()),
                'two_factor_recovery_codes' => encrypt(json_encode(['RECOVERY-ONE-1111', 'RECOVERY-TWO-2222'])),
                'two_factor_confirmed_at' => CarbonImmutable::now('UTC'),
            ])->save();
        }

        return $user->refresh();
    }

    protected function totp(User $user): string
    {
        return app(Google2FA::class)->getCurrentOtp(decrypt($user->two_factor_secret));
    }

    protected function wrongTotp(User $user): string
    {
        $code = $this->totp($user);

        return str_pad((string) (((int) $code + 500000) % 1000000), 6, '0', STR_PAD_LEFT);
    }

    protected function signInWithPassword(User $user, string $password = self::PASSWORD): TestResponse
    {
        return $this->post('/login', ['email' => $user->email, 'password' => $password]);
    }

    /**
     * Full sign-in: password, then TOTP.
     */
    protected function signIn(User $user): TestResponse
    {
        $this->signInWithPassword($user)->assertRedirect('/two-factor-challenge');

        return $this->post('/two-factor-challenge', ['code' => $this->totp($user)]);
    }
}
