<?php

namespace Tests\Feature\Admin;

use App\Livewire\Quality\FlagList;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Feature\Collector\PollTestCase;

/**
 * Batch 9b, B1: the quality list resolves flags inline with the flag page's rules:
 * manage-quality, a note for ignored and reported, "same as" after the confirmation,
 * FlagResolver's audit; refused without the ability, and then no buttons.
 */
class QualityListActionsTest extends PollTestCase
{
    use AdminHelpers;

    private const TK606_ARR_IST = 'bb7871bd-6796-4a27-a1fa-52dccc94b3a2';

    private function flagOf(string $aodbId, string $rule): object
    {
        return DB::table('quality_flags')->where(['flight_id' => $this->flight($aodbId)->id, 'rule' => $rule])->sole();
    }

    private function anyOpenFlag(): object
    {
        return DB::table('quality_flags')->where('state', 'open')->where('rule', '!=', 'duplicate_suspected')->orderBy('id')->first();
    }

    public function test_fixed_and_remove_resolution_from_the_list_are_audited(): void
    {
        $this->poll();
        $steward = $this->user('steward');
        Livewire::actingAs($steward);
        $flag = $this->anyOpenFlag();

        Livewire::test(FlagList::class)
            ->assertSee('wire:click="resolve('.$flag->id.', \'fixed\')"', false)
            ->call('resolve', $flag->id, 'fixed')
            ->assertHasNoErrors()
            ->assertSee('Resolution set: fixed.');
        $row = DB::table('quality_flags')->where('id', $flag->id)->first();
        $this->assertSame(['fixed', $steward->email], [$row->resolution, $row->resolved_by]);

        Livewire::test(FlagList::class)->call('resolve', $flag->id, 'none')->assertSee('Resolution removed.');
        $this->assertNull(DB::table('quality_flags')->where('id', $flag->id)->value('resolution'));

        $audit = DB::table('admin_audit')->where('action', 'quality.resolved')->orderBy('id')->get();
        $this->assertCount(2, $audit);
        $this->assertSame([$steward->email, $steward->email], $audit->pluck('actor')->all());
    }

    public function test_ignored_and_reported_open_a_note_field_and_need_a_note(): void
    {
        $this->poll();
        Livewire::actingAs($this->user('steward'));
        $flag = $this->anyOpenFlag();

        foreach (['ignored', 'reported'] as $resolution) {
            $page = Livewire::test(FlagList::class)
                ->call('resolve', $flag->id, $resolution)
                ->assertSet('noteFor', $flag->id)
                ->assertSet('noteResolution', $resolution)
                ->assertSee('Note for “'.$resolution.'”');
            $this->assertNotSame($resolution, DB::table('quality_flags')->where('id', $flag->id)->value('resolution'), 'nothing saved without a note');

            $page->call('resolve', $flag->id, $resolution)->assertHasErrors('note');
            $page->set('note', 'Checked with the AODB team')->call('resolve', $flag->id, $resolution)
                ->assertHasNoErrors()->assertSet('noteFor', null);
            $row = DB::table('quality_flags')->where('id', $flag->id)->first();
            $this->assertSame([$resolution, 'Checked with the AODB team'], [$row->resolution, $row->resolution_note]);
        }
        $this->assertSame(2, DB::table('admin_audit')->where('action', 'quality.resolved')->count());

        // Cancel closes the note field without saving.
        Livewire::test(FlagList::class)->call('resolve', $flag->id, 'ignored')->call('cancelNote')->assertSet('noteFor', null);
    }

    public function test_same_as_from_the_list_uses_the_confirmation_dialog(): void
    {
        $this->poll();
        Livewire::actingAs($this->user('steward'));
        $flag = $this->flagOf(self::TK606_ARR_TK, 'duplicate_suspected');

        $page = Livewire::test(FlagList::class)
            ->call('askSameAs', $flag->id)
            ->assertSeeInOrder(['FLIGHT TO HIDE', self::TK606_ARR_TK, 'FLIGHT TO KEEP', self::TK606_ARR_IST]);
        $page->call('cancelSameAs')->assertDontSee('FLIGHT TO HIDE');
        $this->assertNull($this->flight(self::TK606_ARR_TK)->same_as_flight_id, 'cancel changes nothing');

        $page->call('askSameAs', $flag->id)->call('confirmSameAs')->assertHasNoErrors()->assertSee('Resolution set: same_as.');
        $this->assertSame($this->flight(self::TK606_ARR_IST)->id, $this->flight(self::TK606_ARR_TK)->same_as_flight_id);
        $this->assertSame(1, DB::table('admin_audit')->where('action', 'quality.resolved')->count());

        // Only duplicate_suspected flags with a related flight can be "same as".
        Livewire::test(FlagList::class)->call('askSameAs', $this->anyOpenFlag()->id)->assertNotFound();
    }

    public function test_without_manage_quality_there_are_no_buttons_and_every_action_is_refused(): void
    {
        $this->poll();
        Livewire::actingAs($this->user('steward'));
        Gate::define('manage-quality', fn () => false);
        $flag = $this->anyOpenFlag();
        $duplicate = $this->flagOf(self::TK606_ARR_TK, 'duplicate_suspected');

        Livewire::test(FlagList::class)->assertOk()
            ->assertDontSee('wire:click="resolve(', false)
            ->assertDontSee('wire:click="askSameAs(', false)
            ->assertSee('Open');
        Livewire::test(FlagList::class)->call('resolve', $flag->id, 'fixed')->assertForbidden();
        Livewire::test(FlagList::class)->call('resolve', $flag->id, 'ignored')->assertForbidden();
        Livewire::test(FlagList::class)->call('askSameAs', $duplicate->id)->assertForbidden();
        Livewire::test(FlagList::class)->set('sameAsFor', $duplicate->id)->call('confirmSameAs')->assertForbidden();

        $this->assertSame(0, DB::table('quality_flags')->whereNotNull('resolution')->count());
        $this->assertNull($this->flight(self::TK606_ARR_TK)->same_as_flight_id);
        $this->assertSame(0, DB::table('admin_audit')->where('action', 'quality.resolved')->count());
    }
}
