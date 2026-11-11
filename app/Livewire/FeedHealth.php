<?php

namespace App\Livewire;

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
 */
class FeedHealth extends Component
{
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

        return view('livewire.feed-health', [
            'now' => $now,
            'feed' => ApiResponses::feed(),
            'last' => $last,
            'lastOk' => $lastOk,
            'pauses' => $pauses,
            'day' => $day,
            'total' => $flights->count(),
            'byFlow' => $flights->countBy(fn ($f) => $f->flow ?? '—')->sortKeys()->all(),
            'byStatus' => $flights->countBy(fn ($f) => $f->status ?? '—')->sortKeys()->all(),
            'flags' => DB::table('quality_flags')->where('state', 'open')
                ->selectRaw('rule, COUNT(*) AS n')->groupBy('rule')->orderBy('rule')->pluck('n', 'rule')->all(),
            'polls24h' => DB::table('polls')->where('started_at', '>=', $now->subDay()->format('Y-m-d H:i:s.u'))
                ->selectRaw('outcome, COUNT(*) AS n')->groupBy('outcome')->orderBy('outcome')->pluck('n', 'outcome')->all(),
            'warnings' => $last?->warnings === null ? [] : json_decode($last->warnings, true),
        ]);
    }
}
