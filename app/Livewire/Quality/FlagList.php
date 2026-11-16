<?php

namespace App\Livewire\Quality;

use App\Admin\FlagCounts;
use App\Flights\Window;
use App\Livewire\AdminPage;
use App\Quality\FlagResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * Quality flags: filters (state, resolution, rule, Kigali day, flight) and counts per rule.
 */
#[Title('Data quality')]
class FlagList extends AdminPage
{
    use WithPagination;

    #[Url]
    public string $state = 'open';

    #[Url]
    public string $resolution = '';

    #[Url]
    public string $rule = '';

    #[Url]
    public string $day = '';

    #[Url]
    public string $flight = '';

    protected function ability(): string
    {
        return 'view-hub';
    }

    public function updating(): void
    {
        $this->resetPage();
    }

    private function filtered(bool $withRule): Builder
    {
        $query = DB::table('quality_flags as q')->join('flights as f', 'f.id', '=', 'q.flight_id');

        if (in_array($this->state, ['open', 'cleared'], true)) {
            $query->where('q.state', $this->state);
        }
        if ($this->resolution === 'none') {
            $query->whereNull('q.resolution');
        } elseif (in_array($this->resolution, FlagResolver::RESOLUTIONS, true)) {
            $query->where('q.resolution', $this->resolution);
        }
        if ($withRule && $this->rule !== '') {
            $query->where('q.rule', $this->rule);
        }
        if ($this->day !== '') {
            try {
                $window = Window::kigaliDay($this->day);
                $query->where('f.reference_time', '>=', $window->from->format('Y-m-d H:i:s'))
                    ->where('f.reference_time', '<', $window->to->format('Y-m-d H:i:s'));
            } catch (InvalidArgumentException) {
                $this->day = '';
            }
        }
        if (trim($this->flight) !== '') {
            $needle = trim($this->flight);
            $query->where(fn ($q) => $q->where('f.full_flight_number', 'like', '%'.$needle.'%')->orWhere('f.aodb_id', $needle));
        }

        return $query;
    }

    public function render(): View
    {
        $now = CarbonImmutable::now('UTC');
        $openNow = DB::table('quality_flags')->where('state', 'open')->count();

        return view('livewire.quality.flag-list', [
            // Display only: totals and 24-hour trends from first_detected_at / cleared_at.
            'openNow' => $openNow,
            'openTrend' => $openNow - FlagCounts::openAt($now->subDay()),
            'clearedDay' => FlagCounts::clearedBetween($now->subDay(), $now),
            'clearedPrev' => FlagCounts::clearedBetween($now->subDays(2), $now->subDay()),
            'counts' => $this->filtered(false)->selectRaw('q.rule, COUNT(*) AS n')->groupBy('q.rule')->orderBy('q.rule')->pluck('n', 'rule')->all(),
            'rules' => DB::table('quality_flags')->distinct()->orderBy('rule')->pluck('rule')->all(),
            'resolutions' => FlagResolver::RESOLUTIONS,
            'flags' => $this->filtered(true)
                ->orderByRaw("q.state = 'open' DESC")->orderByDesc('q.last_detected_at')->orderByDesc('q.id')
                ->select(['q.*', 'f.aodb_id', 'f.full_flight_number', 'f.flow', 'f.reference_time'])
                ->paginate(50),
        ]);
    }
}
