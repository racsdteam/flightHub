<?php

namespace Tests\Feature\Admin;

use App\Api\ApiKeys;
use App\Models\User;
use App\Quality\FlagResolver;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Collector\PollTestCase;

/**
 * G3: an audit row for every C2 action; no C3 secret in admin_audit or any log file.
 */
class AdminAuditTest extends PollTestCase
{
    use AdminHelpers;

    private static function lastLine(string $output): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $output))));

        return (string) end($lines);
    }

    public function test_every_audited_action_is_recorded_and_no_secret_is_logged(): void
    {
        Mail::fake();
        $logFiles = fn () => collect(File::glob(storage_path('logs/*.log')))->map(fn ($f) => (string) file_get_contents($f))->implode("\n");
        $logsBefore = strlen($logFiles());
        $secrets = [self::PASSWORD];

        // CLI: user created, reset, deactivated (temporary passwords printed once).
        Artisan::call('hub:admin-create', ['email' => 'new.admin@rac.test', 'name' => 'New Admin', '--role' => 'admin']);
        $secrets[] = self::lastLine(Artisan::output());
        $created = User::where('email', 'new.admin@rac.test')->sole();
        Artisan::call('hub:admin-reset', ['email' => 'new.admin@rac.test']);
        $secrets[] = self::lastLine(Artisan::output());
        Artisan::call('hub:admin-deactivate', ['email' => 'new.admin@rac.test']);

        // Sign-in failure, lock, TOTP failure, recovery code, success, password change, sign-out.
        $user = $this->user(mustChange: true);
        $secrets[] = decrypt($user->two_factor_secret);
        $secrets = [...$secrets, ...$user->recoveryCodes()];
        $this->signInWithPassword($user, 'typo-password-1');
        $this->signInWithPassword($user);
        $wrong = $this->wrongTotp($user);
        $this->post('/two-factor-challenge', ['code' => $wrong]);
        $this->post('/two-factor-challenge', ['recovery_code' => 'RECOVERY-ONE-1111']);
        $this->post('/password', ['current_password' => self::PASSWORD, 'password' => 'another-long-passphrase', 'password_confirmation' => 'another-long-passphrase']);
        $secrets[] = 'another-long-passphrase';
        $secrets[] = session()->getId();
        $this->post('/logout');

        $locked = $this->user('steward');
        foreach (range(1, 5) as $i) {
            $this->signInWithPassword($locked, 'bad-password-'.$i);
        }

        // TOTP enrolment.
        $fresh = $this->user(totp: false);
        $this->actingAs($fresh)->get('/two-factor/enrol');
        $code = $this->totp($fresh->refresh());
        $secrets[] = decrypt($fresh->two_factor_secret);
        $this->post('/two-factor/enrol', ['code' => $code]);
        $secrets = [...$secrets, ...$fresh->refresh()->recoveryCodes()];
        $this->post('/logout');

        // Alerts: stale then recovered.
        $this->poll();
        $this->travel(16)->minutes();
        Artisan::call('hub:check-feed');
        $this->poll();
        Artisan::call('hub:check-feed');

        // A quality resolution by the signed-in user.
        $flag = DB::table('quality_flags')->where('rule', 'type_missing')->first();
        $this->actingAs($user->refresh());
        (new FlagResolver)->resolve($flag->id, 'reported', 'ignored when signed in', 'sent to the AODB team');

        // An API key, to prove keys are not logged either.
        $consumer = DB::table('consumers')->insertGetId(['code' => 'audit-test', 'name' => 'x', 'field_groups' => '[]', 'created_by' => 'TEST']);
        $secrets[] = ApiKeys::issue($consumer, 'k', null, 'TEST')['key'];

        $actions = DB::table('admin_audit')->pluck('action')->unique()->values()->all();
        foreach ([
            'user.created', 'user.reset', 'user.deactivated', 'signin.failed', 'account.locked', 'totp.failed',
            'totp.recovery_code_used', 'signin.success', 'password.changed', 'signout', 'totp.enrolled',
            'alert.stale_sent', 'alert.recovery_sent', 'quality.resolved',
        ] as $action) {
            $this->assertContains($action, $actions);
        }

        $this->assertSame('cli', DB::table('admin_audit')->where('action', 'user.created')->value('actor'));
        $this->assertSame('hub', DB::table('admin_audit')->where('action', 'alert.stale_sent')->value('actor'));
        $this->assertSame($user->email, DB::table('admin_audit')->where('action', 'quality.resolved')->value('actor'));
        $this->assertSame($user->email, DB::table('quality_flag_events')->where('kind', 'resolved')->orderByDesc('id')->value('actor'));
        $failure = json_decode(DB::table('admin_audit')->where('action', 'signin.failed')->value('details'), true);
        $this->assertSame(['email' => $user->email], $failure);

        $haystack = json_encode(DB::table('admin_audit')->get()).substr($logFiles(), $logsBefore);
        foreach (array_filter($secrets) as $secret) {
            $this->assertStringNotContainsString($secret, $haystack);
        }
        $this->assertStringNotContainsString('typo-password-1', $haystack);
        $this->assertStringNotContainsString($wrong, json_encode(DB::table('admin_audit')->get()));
        $this->assertNotNull($created);
    }
}
