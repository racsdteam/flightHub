<?php

namespace Tests\Feature\Admin;

use App\Admin\Abilities;
use App\Admin\Accounts;
use App\Admin\ReturnPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * G1: the refused cases of sign-in, TOTP, password change and roles.
 */
class AdminAccessTest extends TestCase
{
    use AdminHelpers;

    private const FAILED = 'These credentials do not match our records.';

    public function test_no_session_is_sent_to_sign_in(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/password')->assertRedirect('/login');
        $this->get('/two-factor/enrol')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Sign in');
    }

    public function test_wrong_password_and_unknown_email_get_the_same_message(): void
    {
        $user = $this->user();

        $this->signInWithPassword($user, 'wrong-password-123')->assertSessionHasErrors(['email' => self::FAILED]);
        $this->post('/login', ['email' => 'nobody@rac.test', 'password' => 'whatever-123456'])->assertSessionHasErrors(['email' => self::FAILED]);
        $this->assertGuest();
    }

    public function test_five_wrong_passwords_lock_the_account_for_15_minutes_whatever_the_ip(): void
    {
        $user = $this->user();
        foreach (range(1, 5) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])->signInWithPassword($user, 'wrong-password-'.$i);
        }

        $this->assertTrue($user->refresh()->isLocked());
        // The right password is refused while locked, with the same message.
        $this->signInWithPassword($user)->assertSessionHasErrors(['email' => self::FAILED]);

        $this->travel(16)->minutes();
        $this->signInWithPassword($user)->assertRedirect('/two-factor-challenge');
    }

    public function test_five_wrong_totp_codes_lock_the_account(): void
    {
        $user = $this->user();
        $this->signInWithPassword($user)->assertRedirect('/two-factor-challenge');

        foreach (range(1, 5) as $i) {
            $this->post('/two-factor-challenge', ['code' => $this->wrongTotp($user)]);
        }

        $this->assertTrue($user->refresh()->isLocked());
        $this->post('/two-factor-challenge', ['code' => $this->totp($user)])->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_wrong_totp_is_refused(): void
    {
        $user = $this->user();
        $this->signInWithPassword($user);

        $this->post('/two-factor-challenge', ['code' => $this->wrongTotp($user)])->assertRedirect('/two-factor-challenge');
        $this->assertGuest();
        $this->assertSame(1, $user->refresh()->failed_logins);
    }

    public function test_a_recovery_code_works_once(): void
    {
        $user = $this->user();
        $this->signInWithPassword($user);
        $this->post('/two-factor-challenge', ['recovery_code' => 'RECOVERY-ONE-1111'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->post('/logout');

        $this->signInWithPassword($user);
        $this->post('/two-factor-challenge', ['recovery_code' => 'RECOVERY-ONE-1111']);
        $this->assertGuest();
        $this->assertNotContains('RECOVERY-ONE-1111', $user->refresh()->recoveryCodes());
    }

    public function test_no_page_before_totp_enrolment(): void
    {
        $user = $this->user(totp: false, mustChange: true);

        // No TOTP yet: the password step signs in, then every page leads to enrolment.
        $this->signInWithPassword($user)->assertRedirect('/');
        foreach (['/', '/password'] as $uri) {
            $this->get($uri)->assertRedirect('/two-factor/enrol');
        }
        $this->get('/two-factor/enrol')->assertOk()->assertSee('Set up your authenticator')->assertSee('<svg', false);

        $this->post('/two-factor/enrol', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertFalse($user->refresh()->hasConfirmedTotp());

        $this->post('/two-factor/enrol', ['code' => $this->totp($user->refresh())])
            ->assertOk()->assertSee('Recovery codes')->assertSee($user->refresh()->recoveryCodes()[0]);
        $this->assertTrue($user->refresh()->hasConfirmedTotp());

        // Then the forced password change before any other page.
        $this->get('/')->assertRedirect('/password');
    }

    public function test_must_change_password_is_enforced_with_the_password_rules(): void
    {
        $user = $this->user(mustChange: true);
        $this->signIn($user);
        $this->get('/')->assertRedirect('/password');

        $change = fn (string $new) => $this->post('/password', ['current_password' => self::PASSWORD, 'password' => $new, 'password_confirmation' => $new]);
        $change('short-pass')->assertSessionHasErrors('password');
        $change($user->email)->assertSessionHasErrors('password');
        $change(self::PASSWORD)->assertSessionHasErrors('password');
        $this->post('/password', ['current_password' => 'not-it-at-all', 'password' => 'a-brand-new-passphrase', 'password_confirmation' => 'a-brand-new-passphrase'])
            ->assertSessionHasErrors('current_password');

        $change('a-brand-new-passphrase')->assertRedirect('/');
        $this->get('/')->assertOk();
        $this->assertFalse($user->refresh()->must_change_password);
    }

    public function test_steward_is_refused_admin_only_abilities_and_routes(): void
    {
        $steward = $this->user('steward');
        $admin = $this->user('admin');

        foreach (['manage-consumers', 'manage-users', 'view-audit'] as $ability) {
            $this->assertTrue(Gate::forUser($steward)->denies($ability), $ability);
            $this->assertTrue(Gate::forUser($admin)->allows($ability), $ability);
        }
        foreach (['view-hub', 'manage-quality', 'manage-reference'] as $ability) {
            $this->assertTrue(Gate::forUser($steward)->allows($ability), $ability);
        }
        $this->assertTrue(Gate::forUser($admin)->denies('an-ability-nobody-defined'));

        // Every registered route guarded by an admin-only ability refuses the steward (403).
        Route::middleware(['web', 'auth', 'account'])->group(function () {
            foreach (['manage-consumers', 'manage-users', 'view-audit'] as $ability) {
                Route::get("/__test/{$ability}", fn () => 'ok')->middleware("can:{$ability}");
            }
        });
        DB::table('consumers')->insert(['code' => 'kia-pulse', 'name' => 'KIA PULSE', 'field_groups' => '[]', 'created_by' => 'TEST']);
        $guarded = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => array_intersect(
            ['can:manage-consumers', 'can:manage-users', 'can:view-audit'], $r->middleware()) !== [] && in_array('GET', $r->methods(), true));
        $uris = $guarded->map(fn ($r) => '/'.str_replace('{code}', 'kia-pulse', $r->uri()))->values();
        $this->assertGreaterThanOrEqual(8, $uris->count(), 'consumers (3), users, audit and the 3 test routes');

        $this->actingAs($steward);
        foreach ($uris as $uri) {
            $this->get($uri)->assertForbidden();
        }
        $this->actingAs($admin);
        foreach ($uris as $uri) {
            $this->get($uri)->assertOk();
        }
        $this->assertSame(array_keys(Abilities::ROLES), ['view-hub', 'manage-quality', 'manage-reference', 'manage-consumers', 'manage-users', 'view-audit']);
    }

    public function test_deactivating_a_user_ends_all_its_sessions(): void
    {
        $user = $this->user();
        DB::table('sessions')->insert([
            ['id' => 'test-session-a', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'test-session-b', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
        ]);
        $this->actingAs($user)->get('/')->assertOk();

        Accounts::deactivate($user, 'cli');

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        // The session in use is closed on its next request.
        $this->get('/')->assertRedirect('/login');
        $this->assertGuest();
    }

    /**
     * UAT-06: a Livewire request answered 419 goes to the sign-in page, which says the session
     * ended (public/js/hub.js; checked headless in the acceptance re-test).
     */
    public function test_a_session_that_ended_sends_livewire_to_a_sign_in_page_that_says_so(): void
    {
        $this->actingAs($this->user());
        $page = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('<script src="'.asset('js/hub.js').'" data-session-ended="'.e(route('login', ['session' => 'ended'])).'" defer></script>', $page);

        $script = (string) file_get_contents(public_path('js/hub.js'));
        $this->assertStringContainsString('window.Livewire.interceptRequest(', $script);
        $this->assertStringContainsString('error.response.status === 419', $script);
        $this->assertStringContainsString('error.preventDefault();', $script);

        $this->assertStringContainsString("target.searchParams.set('return', window.location.pathname + window.location.search);", $script);

        auth()->logout();
        $this->get('/login?session=ended')->assertOk()->assertSee('Your session has ended. Please sign in again.');
        $this->get('/login')->assertOk()->assertDontSee('Your session has ended');
        $this->get('/login?session=other')->assertOk()->assertDontSee('Your session has ended');
    }

    /**
     * A3: after "session ended", signing in (password, then TOTP) returns to the page the user
     * was on.
     */
    public function test_signing_in_after_session_ended_returns_to_the_page(): void
    {
        $user = $this->user();
        $this->get('/login?session=ended&return='.rawurlencode('/flights?flow=ARR&day=2026-10-29'))->assertOk();

        $this->signIn($user)->assertRedirect(url('/flights?flow=ARR&day=2026-10-29'));
    }

    /**
     * A3: only a same-origin path is accepted; anything else signs in to the dashboard.
     */
    public function test_the_return_path_accepts_same_origin_paths_only(): void
    {
        foreach (['https://evil.example/x', '//evil.example/x', '/\\evil.example', '\\evil.example', 'javascript:alert(1)', 'flights',
            "/flights\nX", '/login', '/logout', '/two-factor-challenge', '/livewire-abc/update', '/LOGIN', str_repeat('/a', 1001)] as $return) {
            $this->flushSession();
            auth()->logout();
            $this->get('/login?session=ended&return='.rawurlencode($return))->assertOk();
            $this->assertNull(session('url.intended'), $return);
        }
        $this->assertSame('/quality?rule=type_missing', ReturnPath::safe('/quality?rule=type_missing'));

        $this->flushSession();
        $this->get('/login?session=ended&return='.rawurlencode('https://evil.example/x'))->assertOk();
        $this->signIn($this->user())->assertRedirect(url('/'));
    }
}
