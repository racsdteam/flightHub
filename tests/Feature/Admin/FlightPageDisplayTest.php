<?php

namespace Tests\Feature\Admin;

use App\Admin\Display;
use App\Livewire\Flights\FlightPage;
use Livewire\Livewire;
use Tests\Feature\Collector\PollTestCase;

/**
 * Batch 9b, A1–A5: the flight page shows nested objects as labelled rows (never raw JSON),
 * hides empty fields behind a per-card toggle, uses short human labels with the AODB path
 * as a secondary label, and shows values in words (no JSON quotes, Yes / No, —).
 */
class FlightPageDisplayTest extends PollTestCase
{
    use AdminHelpers;

    public function test_nested_objects_are_rows_and_values_are_words(): void
    {
        $this->poll();
        Livewire::actingAs($this->user('steward'));

        $page = Livewire::test(FlightPage::class, ['aodbId' => self::KQ470]);
        $html = preg_replace('/\swire:(snapshot|effects)="[^"]*"/', '', $page->html());

        // aircraftDetails is a heading with its fields under it, not JSON.
        $page->assertSeeInOrder(['Aircraft details', 'aircraftDetails', 'Type name', 'aircraftDetails.acTypeName', 'Embraer']);
        $this->assertDoesNotMatchRegularExpression('/\{&quot;|\{"[a-zA-Z]+":/', $html, 'no raw JSON object');
        // The NB0 → NBO correction, without JSON quotes, in the cards and the change history.
        $page->assertSee('Corrected')->assertSee('NB0')->assertDontSee('&quot;NB0&quot;', false)->assertDontSee('&quot;NBO&quot;', false);
        $page->assertSeeText('Field changed')->assertDontSeeText('field_changed');
        // Human labels, the path only as a secondary label; no AODB wording or typos visible.
        $page->assertSeeInOrder(['Scheduled in-block (SIBT)', 'sibt']);
        $page->assertSeeText('Delays')->assertSeeText('No data from the AODB');
        $page->assertDontSeeText('D-0');
    }

    public function test_empty_fields_are_hidden_until_the_card_toggle_shows_them(): void
    {
        $this->poll();
        Livewire::actingAs($this->user('steward'));

        $page = Livewire::test(FlightPage::class, ['aodbId' => self::KQ470])
            ->assertSee('empty fields · Show')
            ->assertDontSeeText('Target off-block (TOBT)');
        $page->set('showEmpty.acdm', true)
            ->assertSeeText('Target off-block (TOBT)')
            ->assertSeeText('Target start-up approval (TSAT)')
            ->assertDontSeeText('Approuval')                           // the AODB's typo stays in the tooltip only
            ->assertSee('title="The flight Target Start-up Approuval Time (TSAT)', false)
            ->assertSee('empty fields · Hide');
        $page->set('showEmpty.acdm', false)->assertDontSeeText('Target off-block (TOBT)');
    }

    public function test_values_in_words(): void
    {
        $this->assertSame(['Yes', 'No', '—', '—', 'NB0', 'Type name: Embraer · Wake category: M'], [
            Display::text(true), Display::text(false), Display::text(null), Display::text(''),
            Display::text(Display::decode('"NB0"')), Display::text(['acTypeName' => 'Embraer', 'wtc' => 'M'], 'aircraftDetails'),
        ]);
        $this->assertTrue(Display::isEmpty(['a' => null, 'b' => ['c' => '']]));
        $this->assertFalse(Display::isEmpty(['a' => 0]));
        $rows = Display::rows('dlCodes.*', ['aodb' => [], 'typeb' => [['code' => '93', 'delay' => 14, 'info' => null]]], false);
        $this->assertSame(['group', 'table'], array_column($rows, 'kind'));
        $this->assertSame('From Type B messages', $rows[1]['label']);
    }
}
