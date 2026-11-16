<?php

namespace App\Livewire\Flights;

use App\Flights\FlightQuery;
use App\Flights\Window;
use App\Livewire\AdminPage;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * Flights of a Kigali day: the API's list (in feed, not same-as) unless "all"
 * is ticked. Refreshes every 60 s. Shown 50 rows a page (25 / 50 / 100).
 */
#[Title('Flights')]
class DayView extends AdminPage
{
    use WithPagination;

    public const PER_PAGE = [25, 50, 100];

    #[Url]
    public string $day = '';

    #[Url]
    public string $flow = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $airline = '';

    #[Url]
    public bool $flagged = false;

    #[Url]
    public string $q = '';

    #[Url]
    public bool $all = false;

    #[Url]
    public int $perPage = 50;

    protected function ability(): string
    {
        return 'view-hub';
    }

    public function updating(string $property): void
    {
        if ($property !== 'paginators') {
            $this->resetPage();
        }
    }

    private function window(): Window
    {
        try {
            return Window::kigaliDay($this->day);
        } catch (InvalidArgumentException) {
            $this->day = CarbonImmutable::now('Africa/Kigali')->format('Y-m-d');

            return Window::kigaliDay($this->day);
        }
    }

    public function render(): View
    {
        $window = $this->window();
        $flights = (new FlightQuery)->flightsInWindow($window);
        if (! $this->all) {
            $flights = $flights->filter(fn ($f) => $f->in_feed && $f->same_as_flight_id === null);
        }

        $flags = DB::table('quality_flags')->where('state', 'open')->whereIn('flight_id', $flights->pluck('id')->all())
            ->selectRaw('flight_id, COUNT(*) AS n')->groupBy('flight_id')->pluck('n', 'flight_id')->all();
        $statuses = $flights->pluck('status')->filter()->unique()->sort()->values()->all();
        $airlines = $flights->pluck('airline_iata')->filter()->unique()->sort()->values()->all();

        $q = mb_strtoupper(trim($this->q));
        $rows = $flights
            ->filter(fn ($f) => $this->flow === '' || $f->flow === $this->flow)
            ->filter(fn ($f) => $this->status === '' || $f->status === $this->status)
            ->filter(fn ($f) => $this->airline === '' || $f->airline_iata === $this->airline)
            ->filter(fn ($f) => ! $this->flagged || isset($flags[$f->id]))
            ->filter(fn ($f) => $q === '' || str_contains(mb_strtoupper((string) $f->full_flight_number), $q) || str_contains(mb_strtoupper((string) $f->reg), $q))
            ->map(fn ($f) => (object) [
                'aodb_id' => $f->aodb_id,
                'flight' => $f->full_flight_number ?? '—',
                'airline' => $f->airline_iata ?? $f->airline_icao,
                'flow' => $f->flow,
                'other' => $f->flow === 'ARR' ? $f->adep_iata : $f->ades_iata,
                'scheduled' => $f->flow === 'ARR' ? $f->sibt : $f->sobt,
                'estimate' => $f->flow === 'ARR' ? ($f->eibt ?? $f->eldt) : ($f->eobt ?? $f->tobt ?? $f->etot),
                'actual' => $f->flow === 'ARR' ? $f->aibt : $f->aobt,
                'status' => $f->status,
                'milestone' => $f->milestone,
                'stand' => $f->pkg,
                'reg' => $f->reg,
                'type' => $f->ac_type_icao ?? $f->ac_type_iata,
                'flags' => $flags[$f->id] ?? 0,
                'hidden' => ! $f->in_feed ? 'removed' : ($f->same_as_flight_id !== null ? 'same as' : null),
            ])
            ->values();

        $perPage = in_array($this->perPage, self::PER_PAGE, true) ? $this->perPage : 50;
        $page = min(max(1, $this->getPage()), max(1, (int) ceil($rows->count() / $perPage)));

        return view('livewire.flights.day-view', [
            'window' => $window,
            'rows' => new LengthAwarePaginator($rows->forPage($page, $perPage)->values(), $rows->count(), $perPage, $page, ['pageName' => 'page']),
            'perPageOptions' => self::PER_PAGE,
            'statuses' => $statuses,
            'airlines' => $airlines,
        ]);
    }
}
