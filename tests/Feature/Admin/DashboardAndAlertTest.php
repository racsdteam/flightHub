<?php

namespace Tests\Feature\Admin;

use App\Flights\FlightQuery;
use App\Flights\Window;
use App\Livewire\FeedHealth;
use App\Mail\FeedStateMail;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Feature\Collector\PollTestCase;

/**
 * G4 (dashboard on the fixture, fixed clock) and G5 (stale / recovery alert).
 */
class DashboardAndAlertTest extends PollTestCase
{
    use AdminHelpers;

    public function test_dashboard_values_on_the_fixture(): void
    {
        $this->poll();
        $this->actingAs($this->user('steward'));
        $expected = (new FlightQuery)->flightsInWindow(Window::kigaliDay('2026-10-29'))->filter(fn ($f) => $f->in_feed && $f->same_as_flight_id === null);

        Livewire::test(FeedHealth::class)
            ->assertSee('wire:poll.60s', false)
            ->assertSeeInOrder(['Feed state: OK', 'Feed', 'OK'])
            ->assertSee('09:42:30')                                  // last good poll, Kigali time
            ->assertSee('title="UTC 2026-10-29 07:42:30"', false)    // UTC in the title attribute
            ->assertSee('just now')
            ->assertSeeInOrder(['Last poll', 'title="Outcome: ok"', 'OK', 'HTTP 200'], false)
            ->assertSeeInOrder(['AODB pauses', 'None'])
            ->assertSeeInOrder(["Today's flights", (string) $expected->count(), 'Total flights'], false)
            ->assertSeeInOrder([(string) $expected->where('flow', 'ARR')->count(), 'Arrivals (ARR)', (string) $expected->where('flow', 'DEP')->count(), 'Departures (DEP)'])
            ->assertSeeInOrder([(string) DB::table('quality_flags')->where('state', 'open')->count(), 'Open quality flags'])
            ->assertSeeInOrder(['Polls, last 24 h', '1', '(expected 288)'])
            ->assertSeeInOrder(['Successful (1)', 'Failed (0)', 'Paused (0)']);
    }

    public function test_the_stale_banner_appears_on_every_screen_after_15_minutes(): void
    {
        $this->poll();
        $this->actingAs($this->user());

        $this->get('/')->assertOk()->assertDontSee('Feed stale');
        $this->travel(15)->minutes();
        $this->get('/')->assertDontSee('Feed stale');
        $this->travel(1)->seconds();
        $this->get('/')->assertSee('Feed stale');
        $this->get('/password')->assertSee('Feed stale');
    }

    public function test_one_stale_email_and_one_recovery_email_to_active_admins_only(): void
    {
        Mail::fake();
        // ALERT_EXTRA_RECIPIENTS empty = none.
        config(['mail.alert_extra_recipients' => '']);
        $admins = [$this->user('admin'), $this->user('admin')];
        $this->user('admin', active: false);
        $this->user('steward');
        $this->poll();

        Artisan::call('hub:check-feed');
        Mail::assertNothingSent();

        $this->travel(16)->minutes();
        Artisan::call('hub:check-feed');
        Artisan::call('hub:check-feed');
        Mail::assertSent(FeedStateMail::class, 2);
        foreach ($admins as $admin) {
            Mail::assertSent(FeedStateMail::class, fn ($m) => $m->hasTo($admin->email) && $m->state === 'stale');
        }

        $this->poll();
        Artisan::call('hub:check-feed');
        Artisan::call('hub:check-feed');
        Mail::assertSent(FeedStateMail::class, 4);
        Mail::assertSent(FeedStateMail::class, fn ($m) => $m->state === 'ok');
        $this->assertSame(1, DB::table('admin_audit')->where('action', 'alert.stale_sent')->count());
        $this->assertSame(1, DB::table('admin_audit')->where('action', 'alert.recovery_sent')->count());
    }

    public function test_extra_recipients_get_one_stale_and_one_recovery_email_each(): void
    {
        Mail::fake();
        Log::spy();
        [$first, $second] = [$this->user('admin'), $this->user('admin')];
        $this->user('steward');
        // D-055: an extra address listed twice or also an admin's gets one mail; an invalid entry is skipped and logged.
        config(['mail.alert_extra_recipients' => ' it.mailbox@rac.test, not-an-address,, IT.Mailbox@rac.test ,'.strtoupper($second->email).', ops@rac.test']);
        $this->poll();

        $this->travel(16)->minutes();
        Artisan::call('hub:check-feed');
        $this->assertStringContainsString('ok → stale; 2 admins and 2 extra recipients emailed.', Artisan::output());
        Artisan::call('hub:check-feed');

        Mail::assertSent(FeedStateMail::class, 4);
        foreach ([$first->email, $second->email, 'it.mailbox@rac.test', 'ops@rac.test'] as $address) {
            $this->assertCount(1, Mail::sent(FeedStateMail::class, fn ($m) => $m->hasTo($address) && $m->state === 'stale'), $address);
        }
        Mail::assertNotSent(FeedStateMail::class, fn ($m) => $m->hasTo('not-an-address'));

        $this->poll();
        Artisan::call('hub:check-feed');
        Artisan::call('hub:check-feed');

        Mail::assertSent(FeedStateMail::class, 8);
        foreach ([$first->email, $second->email, 'it.mailbox@rac.test', 'ops@rac.test'] as $address) {
            $this->assertCount(1, Mail::sent(FeedStateMail::class, fn ($m) => $m->hasTo($address) && $m->state === 'ok'), $address);
        }
        // Logged once per change of state, not on every run.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => str_contains($message, 'ALERT_EXTRA_RECIPIENTS') && $context === ['entry' => 'not-an-address'])
            ->twice();
        $details = json_decode(DB::table('admin_audit')->where('action', 'alert.stale_sent')->value('details'), true);
        $this->assertSame([4, 2], [$details['recipients'], $details['extra_recipients']]);
    }

    public function test_the_alert_output_says_admin_and_recipient_in_the_singular_for_one(): void
    {
        Mail::fake();
        $this->user('admin');
        config(['mail.alert_extra_recipients' => 'it.mailbox@rac.test']);
        $this->poll();
        $this->travel(16)->minutes();

        Artisan::call('hub:check-feed');

        $this->assertStringContainsString('ok → stale; 1 admin and 1 extra recipient emailed.', Artisan::output());
    }

    public function test_the_extra_recipients_key_is_in_env_example_and_config(): void
    {
        $this->assertStringContainsString("\nALERT_EXTRA_RECIPIENTS=\n", (string) file_get_contents(base_path('.env.example')));
        $this->assertStringContainsString("'alert_extra_recipients' => env('ALERT_EXTRA_RECIPIENTS', '')", (string) file_get_contents(config_path('mail.php')));
    }

    public function test_the_alert_email_holds_times_outcome_and_link_only(): void
    {
        $mail = new FeedStateMail('stale', '2026-10-29T07:42:30Z', 'timeout', '2026-10-29 08:00:00.000000');

        $mail->assertSeeInText('STALE');
        $mail->assertSeeInText('2026-10-29 09:42:30 Kigali time (2026-10-29 07:42:30 UTC)');
        $mail->assertSeeInText('timeout');
        $mail->assertSeeInText(url('/'));
    }
}
