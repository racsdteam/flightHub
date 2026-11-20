<?php

namespace Tests\Feature\Admin;

use App\Admin\ReapplyPending;
use App\Api\ApiKeys;
use App\Livewire\AuditLog;
use App\Livewire\Consumers\ConsumerList;
use App\Livewire\Consumers\ConsumerPage;
use App\Livewire\Consumers\ConsumerUsage;
use App\Livewire\Quality\FlagPage;
use App\Livewire\Reference\AircraftTypes;
use App\Livewire\Reference\AirportCodes;
use App\Livewire\Reference\Stands;
use App\Livewire\Users\UserList;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\Feature\Collector\PollTestCase;

/**
 * H1–H5 on the screens (Livewire components, as the signed-in user).
 */
class ScreensTest extends PollTestCase
{
    use AdminHelpers;

    private const TK606_ARR_IST = 'bb7871bd-6796-4a27-a1fa-52dccc94b3a2';

    private function consumer(string $code = 'kia-pulse', array $groups = ['route']): int
    {
        return DB::table('consumers')->insertGetId(['code' => $code, 'name' => strtoupper($code), 'field_groups' => json_encode($groups), 'created_by' => 'TEST']);
    }

    public function test_steward_is_refused_every_consumers_users_and_audit_screen_and_action(): void
    {
        $this->consumer();
        Livewire::actingAs($this->user('steward'));

        Livewire::test(ConsumerList::class)->assertForbidden();
        Livewire::test(ConsumerPage::class, ['code' => 'kia-pulse'])->assertForbidden();
        Livewire::test(ConsumerUsage::class, ['code' => 'kia-pulse'])->assertForbidden();
        Livewire::test(UserList::class)->assertForbidden();
        Livewire::test(AuditLog::class)->assertForbidden();
    }

    public function test_steward_may_resolve_flags_and_edit_reference_data(): void
    {
        $this->poll();
        $steward = $this->user('steward');
        Livewire::actingAs($steward);
        $flag = DB::table('quality_flags')->where('rule', 'type_missing')->value('id');

        Livewire::test(FlagPage::class, ['flag' => $flag])->call('resolve', 'fixed')->assertHasNoErrors();
        $this->assertSame(['fixed', $steward->email], [DB::table('quality_flags')->where('id', $flag)->value('resolution'), DB::table('quality_flags')->where('id', $flag)->value('resolved_by')]);

        Livewire::test(FlagPage::class, ['flag' => $flag])->call('resolve', 'ignored')->assertHasErrors('note');
        Livewire::test(FlagPage::class, ['flag' => $flag])->set('note', 'known issue')->call('resolve', 'reported')->assertHasNoErrors();

        Livewire::test(Stands::class)->call('create')->set('form.code', 'a2')->call('save')->assertHasNoErrors();
        $stand = DB::table('stands')->sole();
        $this->assertSame(['A2', $steward->name, now('Africa/Kigali')->format('Y-m-d')], [$stand->code, $stand->reviewed_by, $stand->reviewed_on]);
    }

    public function test_reference_screens_validate_with_the_spec_patterns(): void
    {
        Livewire::actingAs($this->user());

        Livewire::test(AirportCodes::class)->call('create')
            ->set('form.aodb_value', 'NB0')->set('form.corrected_iata', 'NBOX')->set('form.corrected_icao', 'HKJ')->set('form.reason', 'x')
            ->call('save')->assertHasErrors(['form.aodb_value', 'form.corrected_iata', 'form.corrected_icao']);
        Livewire::test(AircraftTypes::class)->call('create')
            ->set('form.airline_iata', 'WBX')->set('form.ac_type_iata', 'D')->set('form.wtc', 'Q')->set('form.min_turnaround_minutes', '-5')
            ->call('save')->assertHasErrors(['form.airline_iata', 'form.ac_type_iata', 'form.wtc', 'form.min_turnaround_minutes']);
        Livewire::test(AircraftTypes::class)->call('create')->set('form.airline_iata', '*')->set('form.ac_type_iata', 'dh8')->call('save')->assertHasNoErrors();
        $this->assertSame(['*', 'DH8'], [DB::table('aircraft_types')->value('airline_iata'), DB::table('aircraft_types')->value('ac_type_iata')]);
    }

    public function test_a_reference_edit_is_corrected_by_the_scheduled_reapply(): void
    {
        $this->poll();
        Livewire::actingAs($this->user());
        $this->assertFalse(ReapplyPending::isPending());

        Livewire::test(AirportCodes::class)->call('create')
            ->set('form.aodb_value', 'TK')->set('form.corrected_iata', 'ist')->set('form.corrected_icao', 'ltfm')->set('form.reason', 'Istanbul written as the airline code')
            ->call('save')->assertHasNoErrors();

        $this->assertTrue(ReapplyPending::isPending());
        $audit = json_decode(DB::table('admin_audit')->where('action', 'reference.created')->value('details'), true);
        $this->assertSame(['TK', 'IST', 'LTFM'], [$audit['after']['aodb_value'], $audit['after']['corrected_iata'], $audit['after']['corrected_icao']]);
        // Not inside the web request: nothing corrected yet.
        $this->assertSame('TK', $this->flight(self::TK606_ARR_TK)->adep_iata);

        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'hub:reapply-corrections --if-pending'));
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        Artisan::call('hub:reapply-corrections', ['--if-pending' => true]);

        $this->assertSame(['IST', 'LTFM'], [$this->flight(self::TK606_ARR_TK)->adep_iata, $this->flight(self::TK606_ARR_TK)->adep_icao]);
        $this->assertSame(2, DB::table('change_events')->where(['source' => 'correction', 'flight_id' => $this->flight(self::TK606_ARR_TK)->id])->count());
        $this->assertFalse(ReapplyPending::isPending());
        Artisan::call('hub:reapply-corrections', ['--if-pending' => true]);
        $this->assertStringContainsString('nothing pending', Artisan::output());

        // Editing then deactivating are audited with before / after and mark pending again.
        $id = DB::table('airport_code_corrections')->where('aodb_value', 'TK')->value('id');
        Livewire::test(AirportCodes::class)->call('edit', $id)->set('form.reason', 'Reviewed again')->call('save');
        Livewire::test(AirportCodes::class)->call('toggle', $id);
        $updated = json_decode(DB::table('admin_audit')->where('action', 'reference.updated')->value('details'), true);
        $this->assertSame(['Istanbul written as the airline code', 'Reviewed again'], [$updated['before']['reason'], $updated['after']['reason']]);
        $this->assertSame(1, DB::table('admin_audit')->where('action', 'reference.deactivated')->count());
        $this->assertTrue(ReapplyPending::isPending());
    }

    public function test_reactivating_a_reference_row_is_a_new_review_and_deactivating_is_not(): void
    {
        $admin = $this->user('admin');
        $steward = $this->user('steward');
        Livewire::actingAs($admin);
        Livewire::test(AirportCodes::class)->call('create')
            ->set('form.aodb_value', 'TK')->set('form.corrected_iata', 'IST')->set('form.corrected_icao', 'LTFM')->set('form.reason', 'Istanbul written as the airline code')
            ->call('save')->assertHasNoErrors();
        $id = DB::table('airport_code_corrections')->where('aodb_value', 'TK')->value('id');
        $row = fn () => DB::table('airport_code_corrections')->where('id', $id)->first(['active', 'reviewed_by', 'reviewed_on']);
        $this->assertSame([1, $admin->name, '2026-10-29'], array_values((array) $row()));

        // Deactivating (next day, by the steward) keeps the last review.
        $this->travel(1)->days();
        Livewire::actingAs($steward);
        Livewire::test(AirportCodes::class)->call('toggle', $id)->assertHasNoErrors();
        $this->assertSame([0, $admin->name, '2026-10-29'], array_values((array) $row()));

        // Reactivating (a day later) is a new review: the steward, today (Kigali).
        $this->travel(1)->days();
        Livewire::test(AirportCodes::class)->call('toggle', $id)->assertHasNoErrors();
        $this->assertSame([1, $steward->name, '2026-10-31'], array_values((array) $row()));

        $audit = json_decode(DB::table('admin_audit')->where('action', 'reference.reactivated')->value('details'), true);
        $this->assertSame([$admin->name, $steward->name, '2026-10-29', '2026-10-31'], [$audit['before']['reviewed_by'], $audit['after']['reviewed_by'], $audit['before']['reviewed_on'], $audit['after']['reviewed_on']]);
        $this->assertSame($steward->email, DB::table('admin_audit')->where('action', 'reference.reactivated')->value('actor'));
    }

    public function test_a_key_is_shown_once_and_stored_only_as_its_hash(): void
    {
        $this->consumer();
        $admin = $this->user();
        Livewire::actingAs($admin);

        $page = Livewire::test(ConsumerPage::class, ['code' => 'kia-pulse'])->set('label', 'production')->call('issueKey');
        preg_match('/fdh_[0-9A-Za-z]{8}_[0-9A-Za-z]{40}/', $page->html(), $m);
        $key = $m[0];
        $page->assertSee('shown only this once');

        // Not in the component state (snapshot), the session, the audit or the database.
        $this->assertStringNotContainsString($key, json_encode($page->snapshot));
        $this->assertStringNotContainsString($key, serialize(session()->all()));
        $this->assertStringNotContainsString($key, json_encode(DB::table('admin_audit')->get()).json_encode(DB::table('api_keys')->get()));
        $this->assertSame(ApiKeys::hash($key), DB::table('api_keys')->value('secret_hash'));
        $this->assertSame(substr($key, 0, 12), json_decode(DB::table('admin_audit')->where('action', 'key.issued')->value('details'))->prefix);

        // The next render no longer shows it.
        $page->call('saveSettings')->assertDontSee($key);

        // It works, and revoking takes effect on the next call.
        $this->getJson('/api/v1/health', ['Authorization' => 'Bearer '.$key])->assertOk();
        $page->call('revoke', DB::table('api_keys')->value('id'));
        $this->getJson('/api/v1/health', ['Authorization' => 'Bearer '.$key])->assertStatus(401);
    }

    public function test_consumer_settings_groups_and_disable(): void
    {
        $id = $this->consumer('pulse-test', []);
        Livewire::actingAs($this->user());

        Livewire::test(ConsumerPage::class, ['code' => 'pulse-test'])->set('groups', ['route', 'times'])->set('rate', '60')->call('saveSettings')->assertHasNoErrors();
        $this->assertSame(['route', 'times'], json_decode(DB::table('consumers')->where('id', $id)->value('field_groups'), true));
        Livewire::test(ConsumerPage::class, ['code' => 'pulse-test'])->set('groups', ['route', 'identity-x'])->call('saveSettings')->assertHasErrors('groups.1');

        Livewire::test(ConsumerList::class)->call('toggle', $id);
        $this->assertFalse((bool) DB::table('consumers')->where('id', $id)->value('active'));
        $this->assertSame(['consumer.updated', 'consumer.disabled'], DB::table('admin_audit')->where('target_type', 'consumer')->orderBy('id')->pluck('action')->all());
    }

    public function test_same_as_through_the_screen_hides_the_right_flight_from_the_api(): void
    {
        $this->poll();
        $this->consumer('reader', ['route']);
        $key = ApiKeys::issue(DB::table('consumers')->where('code', 'reader')->value('id'), 'k', null, 'TEST')['key'];
        Livewire::actingAs($this->user('steward'));
        $flag = DB::table('quality_flags')->where(['flight_id' => $this->flight(self::TK606_ARR_TK)->id, 'rule' => 'duplicate_suspected'])->value('id');

        Livewire::test(FlagPage::class, ['flag' => $flag])
            ->call('askSameAs')
            ->assertSeeInOrder(['FLIGHT TO HIDE', self::TK606_ARR_TK, 'FLIGHT TO KEEP', self::TK606_ARR_IST])
            ->call('confirmSameAs')
            ->assertHasNoErrors();

        $list = $this->getJson('/api/v1/flights?from=2026-10-27T22:00:00Z&to=2026-10-30T22:00:00Z', ['Authorization' => 'Bearer '.$key])->json('data');
        $tk606Arr = array_column(array_filter($list, fn ($f) => $f['fullFlightNumber'] === 'TK606' && $f['flow'] === 'ARR'), 'id');
        $this->assertSame([self::TK606_ARR_IST], array_values($tk606Arr));
    }

    public function test_user_refusals_and_actions(): void
    {
        $admin = $this->user('admin');
        $steward = $this->user('steward');
        Livewire::actingAs($admin);

        // Refused: deactivating yourself; demoting the last active admin. (Anyone else holding
        // manage-users is an active admin too, so the last admin can only be reached as yourself.)
        Livewire::test(UserList::class)->call('deactivate', $admin->id)->assertSee('you cannot deactivate yourself');
        Livewire::test(UserList::class)->call('changeRole', $admin->id, 'steward')->assertSee('is the last active admin');
        $this->assertSame(['admin', true], [$admin->refresh()->role, $admin->active]);

        // With a second admin, demoting yourself is allowed; the other admin is then the last one.
        $second = $this->user('admin');
        Livewire::test(UserList::class)->call('changeRole', $admin->id, 'steward');
        $this->assertSame('steward', $admin->refresh()->role);
        Livewire::actingAs($second);
        Livewire::test(UserList::class)->call('changeRole', $second->id, 'steward')->assertSee('is the last active admin');

        // Deactivate and reactivate someone else.
        Livewire::test(UserList::class)->call('deactivate', $steward->id);
        $this->assertFalse($steward->refresh()->active);
        Livewire::test(UserList::class)->call('reactivate', $steward->id);
        $this->assertTrue($steward->refresh()->active);

        // Create shows a temporary password once.
        $page = Livewire::test(UserList::class)->call('startCreate')->set('email', 'New.User@RAC.test')->set('name', 'New User')->set('role', 'steward')->call('create');
        preg_match('/<code id="temporary-password">([A-Za-z0-9]{20})<\/code>/', $page->html(), $m);
        $this->assertNotEmpty($m);
        $this->assertStringNotContainsString($m[1], json_encode($page->snapshot));
        $page->call('reactivate', $steward->id)->assertDontSee($m[1]);
        $this->assertSame(1, DB::table('users')->where('email', 'new.user@rac.test')->count());

        // Reset: new temporary password, TOTP reset.
        Livewire::test(UserList::class)->call('resetAccount', $steward->id)->assertSee('Temporary password for '.$steward->email);
        $this->assertNull($steward->refresh()->two_factor_secret);
        $this->assertTrue($steward->must_change_password);

        $actions = DB::table('admin_audit')->pluck('action')->all();
        foreach (['user.role_changed', 'user.deactivated', 'user.reactivated', 'user.created', 'user.reset'] as $action) {
            $this->assertContains($action, $actions);
        }
    }

    public function test_no_secret_reaches_the_audit_or_the_logs(): void
    {
        $logs = fn () => collect(File::glob(storage_path('logs/*.log')))->map(fn ($f) => (string) file_get_contents($f))->implode("\n");
        $before = strlen($logs());
        $this->consumer();
        $admin = $this->user();
        $secrets = [self::PASSWORD, decrypt($admin->two_factor_secret), ...$admin->recoveryCodes(), $this->totp($admin)];
        Livewire::actingAs($admin);

        $page = Livewire::test(ConsumerPage::class, ['code' => 'kia-pulse'])->set('label', 'x')->call('issueKey');
        preg_match('/fdh_[0-9A-Za-z]{8}_[0-9A-Za-z]{40}/', $page->html(), $m);
        $secrets[] = $m[0];
        $users = Livewire::test(UserList::class)->call('startCreate')->set('email', 'a@rac.test')->set('name', 'A')->call('create');
        preg_match('/<code id="temporary-password">([A-Za-z0-9]{20})<\/code>/', $users->html(), $p);
        $secrets[] = $p[1];
        $users->call('resetAccount', $admin->id);
        preg_match('/<code id="temporary-password">([A-Za-z0-9]{20})<\/code>/', $users->html(), $r);
        $secrets[] = $r[1];

        $haystack = json_encode(DB::table('admin_audit')->get()).substr($logs(), $before);
        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $haystack);
        }
    }

    public function test_every_screen_family_renders_csp_clean_html(): void
    {
        $this->poll();
        $this->consumer();
        $flag = DB::table('quality_flags')->value('id');
        $this->actingAs($this->user());

        foreach ([
            '/flights?day=2026-10-29', '/flights/'.self::WB711, '/quality', "/quality/{$flag}", '/reference/airport-codes',
            '/reference/aircraft-types', '/reference/stands', '/consumers', '/consumers/kia-pulse', '/consumers/kia-pulse/usage', '/users', '/audit',
        ] as $uri) {
            $response = $this->get($uri)->assertOk();
            $html = $response->getContent();
            preg_match("/'nonce-([A-Za-z0-9]+)'/", $response->headers->get('Content-Security-Policy'), $nonce);
            preg_match_all('/<(script|style)\b[^>]*>/i', $html, $tags);
            foreach ($tags[0] as $tag) {
                $this->assertTrue(str_contains($tag, 'nonce="'.$nonce[1].'"') || preg_match('/src="'.preg_quote(url('/'), '/').'/', $tag) === 1, "{$uri}: {$tag}");
            }
            $this->assertDoesNotMatchRegularExpression('/\sstyle="|\son[a-z]+="/i', $html, "{$uri}: inline style or event handler");
            $this->assertDoesNotMatchRegularExpression('/(src|href)="(https?:)?\/\/(?!'.preg_quote(parse_url(url('/'), PHP_URL_HOST), '/').')/i', $html, "{$uri}: outside URL");
        }
    }
}
