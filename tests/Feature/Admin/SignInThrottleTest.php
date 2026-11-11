<?php

namespace Tests\Feature\Admin;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * A1 (per-IP sign-in throttle) and A2 (no passkey / WebAuthn route).
 */
class SignInThrottleTest extends TestCase
{
    use AdminHelpers;

    public function test_20_failed_steps_from_one_ip_block_every_attempt_for_the_rest_of_the_minute(): void
    {
        $victim = $this->user();
        $other = $this->user();
        // Spread over many accounts, so no account lock is reached.
        foreach (range(1, 20) as $i) {
            $this->post('/login', ['email' => "nobody{$i}@rac.test", 'password' => 'guess-'.$i])->assertStatus(302);
        }

        // Valid or not, every sign-in attempt from this IP now gets 429.
        $this->signInWithPassword($victim)->assertStatus(429)->assertHeader('Retry-After')->assertSee('Too many sign-in attempts');
        $this->post('/login', ['email' => 'x@rac.test', 'password' => 'y'])->assertStatus(429);
        $this->assertGuest();
        $this->assertSame(1, DB::table('admin_audit')->where('action', 'signin.ip_blocked')->count(), 'audited once per block');

        // Another IP is not affected.
        $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])->signInWithPassword($other)->assertRedirect('/two-factor-challenge');

        $this->travel(61)->seconds();
        $this->signInWithPassword($victim)->assertRedirect('/two-factor-challenge');
    }

    public function test_totp_failures_count_toward_the_ip_block(): void
    {
        $users = collect(range(1, 5))->map(fn () => $this->user());
        foreach ($users as $user) {
            $this->signInWithPassword($user);
            foreach (range(1, 4) as $i) {
                $this->post('/two-factor-challenge', ['code' => $this->wrongTotp($user)]);
            }
            session()->flush();
        }

        $this->post('/two-factor-challenge', ['code' => '123456'])->assertStatus(429);
    }

    public function test_no_passkey_or_webauthn_route_is_registered(): void
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $this->assertDoesNotMatchRegularExpression('/passkey|webauthn/i', $route->uri().' '.$route->getName());
        }
    }
}
