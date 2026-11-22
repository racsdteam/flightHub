<?php

namespace Tests\Feature\Quality;

use App\Livewire\Quality\FlagList;
use App\Quality\FlagResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Feature\Admin\AdminHelpers;
use Tests\Feature\Collector\PollTestCase;

/**
 * Batch 7b, Part B (D-057): "Resolve all N shown" on the quality list.
 */
class BulkResolveTest extends PollTestCase
{
    use AdminHelpers;

    private const RULE = 'capacity_missing';

    private function unresolved(string $rule = self::RULE): int
    {
        return DB::table('quality_flags')->where('rule', $rule)->whereNull('resolution')->count();
    }

    public function test_resolve_all_shown_resolves_every_unresolved_flag_each_with_its_own_event_and_audit_line(): void
    {
        $this->poll();
        // Resolved earlier by someone else (no one signed in yet: the given actor is kept).
        $earlier = DB::table('quality_flags')->where('rule', self::RULE)->orderBy('id')->first();
        (new FlagResolver)->resolve($earlier->id, 'ignored', 'someone.else@rac.test', 'known');
        $steward = $this->user('steward');
        Livewire::actingAs($steward);
        $total = DB::table('quality_flags')->where('rule', self::RULE)->count();
        $n = $total - 1;
        $eventsBefore = DB::table('quality_flag_events')->where('kind', 'resolved')->count();
        $auditBefore = DB::table('admin_audit')->where('action', 'quality.resolved')->count();

        Livewire::test(FlagList::class)
            ->set('state', '')
            ->set('rule', self::RULE)
            ->assertSee("Resolve all {$n} shown")
            ->call('askBulk', 'fixed')
            ->assertSet('bulkResolution', 'fixed')
            ->assertSee("Resolve {$n} flags as “fixed”", false)
            ->assertSee('all days')
            ->call('confirmBulk')
            ->assertHasNoErrors()
            ->assertSet('bulkResolution', null)
            ->assertSee("Resolved {$n} flags as fixed.");

        $this->assertSame(0, $this->unresolved());
        $this->assertSame($n, DB::table('quality_flags')->where(['rule' => self::RULE, 'resolution' => 'fixed', 'resolved_by' => $steward->email])->count());
        $this->assertSame(['ignored', 'someone.else@rac.test'], [DB::table('quality_flags')->where('id', $earlier->id)->value('resolution'), DB::table('quality_flags')->where('id', $earlier->id)->value('resolved_by')]);
        $this->assertSame($eventsBefore + $n, DB::table('quality_flag_events')->where('kind', 'resolved')->count());
        $this->assertSame($auditBefore + $n, DB::table('admin_audit')->where('action', 'quality.resolved')->count());
        $bulk = DB::table('admin_audit')->where('action', 'quality.bulk_resolved')->sole();
        $this->assertSame($steward->email, $bulk->actor);
        $this->assertSame([
            'rule' => self::RULE,
            'filters' => ['state' => 'all', 'resolution' => 'all', 'day' => null, 'flight' => null],
            'resolution' => 'fixed',
            'note' => null,
            'count' => $n,
            'skipped' => 0,
        ], json_decode($bulk->details, true));
    }

    public function test_refused_without_the_ability(): void
    {
        $this->poll();
        Livewire::actingAs($this->user('steward'));
        Gate::define('manage-quality', fn () => false);

        Livewire::test(FlagList::class)->set('rule', self::RULE)->assertOk()
            ->assertDontSee('Resolve all')
            ->assertDontSee('askBulk', false)
            ->call('askBulk', 'fixed')->assertForbidden();
        Livewire::test(FlagList::class)->set('rule', self::RULE)->call('confirmBulk')->assertForbidden();
        $this->assertSame(0, DB::table('quality_flags')->whereNotNull('resolution')->count());
    }

    public function test_refused_without_a_rule_filter_and_for_same_as(): void
    {
        $this->poll();
        Livewire::actingAs($this->user('steward'));

        Livewire::test(FlagList::class)->assertDontSee('Resolve all')->call('askBulk', 'fixed')->assertStatus(422);
        Livewire::test(FlagList::class)->set('rule', 'duplicate_suspected')->call('askBulk', 'same_as')->assertStatus(422);
        Livewire::test(FlagList::class)->set('rule', self::RULE)->call('askBulk', 'none')->assertStatus(422);
        Livewire::test(FlagList::class)->set('rule', self::RULE)->call('confirmBulk')->assertStatus(422);
        $this->assertSame(0, DB::table('quality_flags')->whereNotNull('resolution')->count());
    }

    public function test_a_note_is_required_for_ignored_and_reported(): void
    {
        $this->poll();
        Livewire::actingAs($this->user('steward'));
        $n = $this->unresolved();

        foreach (['ignored', 'reported'] as $resolution) {
            Livewire::test(FlagList::class)->set('rule', self::RULE)
                ->call('askBulk', $resolution)
                ->assertSee('(required)')
                ->call('confirmBulk')
                ->assertHasErrors('bulkNote')
                ->assertSee("A note is required for {$resolution}.");
            $this->assertSame($n, $this->unresolved());
        }

        Livewire::test(FlagList::class)->set('rule', self::RULE)
            ->call('askBulk', 'reported')->set('bulkNote', 'Reported to Innov\'ATM, ticket 4411')->call('confirmBulk')
            ->assertHasNoErrors();
        $this->assertSame($n, DB::table('quality_flags')->where(['rule' => self::RULE, 'resolution' => 'reported', 'resolution_note' => 'Reported to Innov\'ATM, ticket 4411'])->count());
    }

    public function test_flags_resolved_by_someone_else_meanwhile_are_untouched_and_counted(): void
    {
        $this->poll();
        $steward = $this->user('steward');
        Livewire::actingAs($steward);
        $n = $this->unresolved();

        $list = Livewire::test(FlagList::class)->set('rule', self::RULE)->call('askBulk', 'fixed');
        $taken = $list->get('bulkIds')[0];
        DB::table('quality_flags')->where('id', $taken)->update(['resolution' => 'reported', 'resolved_by' => 'other@rac.test', 'resolution_note' => 'theirs']);
        $list->call('confirmBulk')->assertSee('Resolved '.($n - 1).' flags as fixed; 1 skipped (resolved by someone else meanwhile).');

        $row = DB::table('quality_flags')->where('id', $taken)->first();
        $this->assertSame(['reported', 'other@rac.test', 'theirs'], [$row->resolution, $row->resolved_by, $row->resolution_note]);
        $details = json_decode(DB::table('admin_audit')->where('action', 'quality.bulk_resolved')->value('details'), true);
        $this->assertSame([$n - 1, 1], [$details['count'], $details['skipped']]);
    }

    public function test_at_most_500_flags_per_action(): void
    {
        $this->poll();
        Livewire::actingAs($this->user('steward'));
        $flightId = DB::table('flights')->orderBy('id')->value('id');
        $at = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
        $rows = [];
        for ($i = 1; $i <= 501; $i++) {
            $rows[] = ['flight_id' => $flightId, 'rule' => 'bulk_test_rule', 'field' => "f{$i}", 'related_flight_id' => null, 'flag_key' => "bulk_test_rule|f{$i}|",
                'state' => $i === 501 ? 'cleared' : 'open', 'details' => null, 'first_detected_at' => $at, 'last_detected_at' => $at,
                'cleared_at' => $i === 501 ? $at : null, 'created_at' => substr($at, 0, 19), 'updated_at' => substr($at, 0, 19)];
        }
        DB::table('quality_flags')->insert($rows);

        Livewire::test(FlagList::class)->set('state', '')->set('rule', 'bulk_test_rule')
            ->assertSee('Resolve all 501 shown:')
            ->assertSee('Narrow the filters')
            ->assertDontSee("askBulk('fixed')", false)
            ->call('askBulk', 'fixed')
            ->assertHasErrors('bulk')
            ->assertSet('bulkResolution', null);

        // Narrowed to the open ones: exactly 500.
        $list = Livewire::test(FlagList::class)->set('state', 'open')->set('rule', 'bulk_test_rule')
            ->assertSee('Resolve all 500 shown')
            ->call('askBulk', 'fixed')
            ->assertSet('bulkResolution', 'fixed');
        $this->assertCount(500, $list->get('bulkIds'));
        $list->call('confirmBulk')->assertSee('Resolved 500 flags as fixed.');
        $this->assertSame(1, $this->unresolved('bulk_test_rule'));
    }

    public function test_changing_a_filter_closes_the_confirmation_and_the_ids_cannot_be_set_by_the_client(): void
    {
        $this->poll();
        Livewire::actingAs($this->user('steward'));

        Livewire::test(FlagList::class)->set('rule', self::RULE)->call('askBulk', 'fixed')
            ->assertSet('bulkResolution', 'fixed')
            ->set('day', '2026-10-29')
            ->assertSet('bulkResolution', null)
            ->assertSet('bulkIds', []);

        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(FlagList::class)->set('rule', self::RULE)->set('bulkIds', [1, 2, 3]);
    }
}
