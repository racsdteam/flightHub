<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;
use Tests\Feature\Collector\PollTestCase;

/**
 * G5: /changes paging over filtered events.
 */
class ApiChangesTest extends PollTestCase
{
    use ApiHelpers;

    public function test_paging_follows_scanned_ids_so_skipped_events_never_block(): void
    {
        $key = $this->consumerKey([]);
        $this->poll();
        $all = DB::table('change_events')->orderBy('id')->get();
        $visible = $all->filter(fn ($e) => $e->field === null || $e->field === 'hub.sameAs')->pluck('id')->map(fn ($id) => (int) $id)->all();

        $seen = [];
        $cursor = 0;
        $pages = 0;
        do {
            $page = $this->api("changes?cursor={$cursor}&limit=10", $key)->assertOk();
            $this->assertLessThanOrEqual(10, count($page->json('data')));
            $seen = [...$seen, ...array_column($page->json('data'), 'cursor')];
            $scanned = $all->where('id', '>', $cursor)->take(10)->last();
            $this->assertSame((int) $scanned->id, $page->json('meta.nextCursor'), 'nextCursor = the last event id scanned');
            $cursor = $page->json('meta.nextCursor');
            $pages++;
        } while ($page->json('meta.hasMore'));

        $this->assertSame($visible, $seen);
        $this->assertSame((int) $all->last()->id, $cursor);
        $this->assertSame((int) $all->last()->id, $page->json('meta.headCursor'));
        $this->assertSame((int) ceil($all->count() / 10), $pages);
    }

    public function test_a_page_of_only_hidden_events_still_advances(): void
    {
        $key = $this->consumerKey([]);
        $this->poll();
        // The 4 correction events of KQ470 / KQ478 are adepIata / adepIcao: outside identity.
        $correction = (int) DB::table('change_events')->where('source', 'correction')->min('id');

        $page = $this->api('changes?cursor='.($correction - 1).'&limit=1', $key)->assertOk();

        $this->assertSame([], $page->json('data'));
        $this->assertSame($correction, $page->json('meta.nextCursor'));
        $this->assertTrue($page->json('meta.hasMore'));
    }

    public function test_limit_is_capped_at_1000_and_cursor_is_required(): void
    {
        $key = $this->consumerKey(['route']);
        $this->poll();

        $this->assertSame(1000, $this->api('changes?cursor=0&limit=5000', $key)->assertOk()->json('meta.limit'));
        $this->assertSame(500, $this->api('changes?cursor=0', $key)->json('meta.limit'));
        foreach (['changes', 'changes?cursor=-1', 'changes?cursor=abc', 'changes?cursor=0&limit=0'] as $uri) {
            $this->api($uri, $key)->assertStatus(422)->assertJsonPath('error', 'INVALID_PARAMETER');
        }
    }

    public function test_an_event_carries_the_aodb_id_and_no_internal_reference(): void
    {
        $key = $this->consumerKey(['route']);
        $this->poll();

        $event = collect($this->api('changes?cursor=0&limit=1000', $key)->json('data'))->firstWhere('source', 'correction');
        $this->assertSame(['cursor', 'aodbId', 'observedAt', 'kind', 'field', 'old', 'new', 'source'], array_keys($event));
        $this->assertSame('2026-10-29T07:42:30Z', $event['observedAt']);
        $this->assertContains($event['aodbId'], [self::KQ470, self::KQ478]);
    }
}
