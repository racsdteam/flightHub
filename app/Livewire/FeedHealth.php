<?php

namespace App\Livewire;

use App\Admin\Display;
use App\Admin\FlagCounts;
use App\Aodb\HttpAodbClient;
use App\Api\ApiResponses;
use App\Flights\FlightQuery;
use App\Flights\Window;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Feed health (view-hub, read-only, refreshed every 60 s by Livewire polling).
 * The poll filter (all / ok / failed / paused) only narrows the recent polls shown.
 */
class FeedHealth extends Component
{
    public string $pollFilter = 'all';

    public function render(): View
    {
        abort_unless(auth()->user()?->can('view-hub'), 403);

        $now = CarbonImmutable::now('UTC');
        $last = DB::table('polls')->orderByDesc('id')->first();
        $lastOk = DB::table('polls')->where('outcome', 'ok')->orderByDesc('id')->first();

        $pauses = [];
        foreach (['Login back-off' => HttpAodbClient::BACKOFF_KEY, 'Rate-limit pause' => HttpAodbClient::RATE_LIMIT_KEY] as $label => $key) {
            $until = Cache::get($key);
            if ($until !== null && (int) $until > $now->getTimestamp()) {
                $pauses[$label] = CarbonImmutable::createFromTimestampUTC((int) $until);
            }
        }

        // Today's Kigali day, as the API lists it: in feed, not same-as another flight.
        $day = Window::kigaliDay($now->setTimezone('Africa/Kigali')->format('Y-m-d'));
        $flights = (new FlightQuery)->flightsInWindow($day)
            ->filter(fn ($f) => $f->in_feed && $f->same_as_flight_id === null);
        $statusCounts = $flights->countBy(fn ($f) => $f->status ?? 'NONE')->all();
        $byStatus = [];
        foreach (array_keys(Display::STATUS) as $status) {
            $byStatus[$status] = $statusCounts[$status] ?? 0;
        }
        foreach ($statusCounts as $status => $n) {
            $byStatus[$status] ??= $n;     // a status the AODB spec does not list
        }

        $flags = DB::table('quality_flags')->where('state', 'open')
            ->selectRaw('rule, COUNT(*) AS n')->groupBy('rule')->orderByDesc('n')->orderBy('rule')->pluck('n', 'rule')->all();
        $openNow = array_sum($flags);

        // Polls of the last 24 h, in 24 one-hour buckets ending now.
        $since = $now->subDay();
        $buckets = array_fill(0, 24, ['ok' => 0, 'failed' => 0, 'paused' => 0]);
        $totals = ['ok' => 0, 'failed' => 0, 'paused' => 0];
        $polls = DB::table('polls')->where('started_at', '>=', $since->format('Y-m-d H:i:s.u'))->get(['started_at', 'outcome']);
        foreach ($polls as $poll) {
            $i = min(23, max(0, intdiv(CarbonImmutable::parse($poll->started_at, 'UTC')->getTimestamp() - $since->getTimestamp(), 3600)));
            $kind = Display::pollKind($poll->outcome);
            $buckets[$i][$kind]++;
            $totals[$kind]++;
        }

        $recent = DB::table('polls')->orderByDesc('id')
            ->when($this->pollFilter === 'ok', fn ($q) => $q->where('outcome', 'ok'))
            ->when($this->pollFilter === 'paused', fn ($q) => $q->whereIn('outcome', Display::PAUSED_OUTCOMES))
            ->when($this->pollFilter === 'failed', fn ($q) => $q->where('outcome', '!=', 'ok')->whereNotIn('outcome', Display::PAUSED_OUTCOMES))
            ->limit(10)->get();

        return view('livewire.feed-health', [
            'now' => $now,
            'feed' => ApiResponses::feed(),
            'last' => $last,
            'lastOk' => $lastOk,
            'dataAge' => $lastOk === null ? null : Display::duration((int) floor(CarbonImmutable::parse($lastOk->finished_at, 'UTC')->diffInSeconds($now, true))),
            'pauses' => $pauses,
            'day' => $day,
            'total' => $flights->count(),
            'byFlow' => ['ARR' => $flights->where('flow', 'ARR')->count(), 'DEP' => $flights->where('flow', 'DEP')->count()],
            'byStatus' => $byStatus,
            'flags' => $flags,
            'openNow' => $openNow,
            'openTrend' => $openNow - FlagCounts::openAt($now->subDay()),
            'buckets' => $buckets,
            'since' => $since,
            'pollTotals' => $totals,
            'expected' => Display::expectedPollsPerDay($now),
            'warnings' => $last?->warnings === null ? [] : json_decode($last->warnings, true),
            'recent' => $recent,
        ]);
    }
}
