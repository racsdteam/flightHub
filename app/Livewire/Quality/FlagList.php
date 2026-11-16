<?php

namespace App\Livewire\Quality;

use App\Admin\FlagCounts;
use App\Flights\Window;
use App\Livewire\AdminPage;
use App\Quality\FlagResolver;
use App\Quality\FlightRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * Quality flags: filters (state, resolution, rule, Kigali day, flight) and counts per rule;
 * each row can be resolved here as on the flag page (ResolvesFlags: manage-quality, a note
 * for ignored and reported, "same as" after the same confirmation dialog).
 */
#[Title('Data quality')]
class FlagList extends AdminPage
{
    use ResolvesFlags;
    use WithPagination;

    /** The row whose note field is open, and the resolution it is for. */
    public ?int $noteFor = null;

    public ?string $noteResolution = null;

    /** The flag whose "same as" confirmation is open. */
    public ?int $sameAsFor = null;

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

    public function updating(string $property): void
    {
        if (in_array($property, ['state', 'resolution', 'rule', 'day', 'flight'], true)) {
            $this->resetPage();
        }
    }

    public function resolve(int $flagId, string $resolution): void
    {
        Gate::authorize('manage-quality');
        $this->sameAsFor = null;
        $this->resetErrorBag();
        [$known, $value] = $this->askedResolution($resolution);
        abort_unless($known && DB::table('quality_flags')->where('id', $flagId)->exists(), 404);
        if ($this->noteMissing($value)) {
            if ($this->noteFor === $flagId && $this->noteResolution === $value) {
                $this->addError('note', 'A note is required for '.$value.'.');
            }
            [$this->noteFor, $this->noteResolution] = [$flagId, $value];

            return;
        }
        if ($this->applyResolution($flagId, $value)) {
            [$this->noteFor, $this->noteResolution] = [null, null];
        }
    }

    public function cancelNote(): void
    {
        [$this->noteFor, $this->noteResolution, $this->note] = [null, null, ''];
        $this->resetErrorBag();
    }

    public function askSameAs(int $flagId): void
    {
        Gate::authorize('manage-quality');
        $flag = DB::table('quality_flags')->where('id', $flagId)->first();
        abort_unless($flag !== null && $flag->rule === FlightRules::DUPLICATE_SUSPECTED && $flag->related_flight_id !== null, 404);
        [$this->noteFor, $this->noteResolution] = [null, null];
        $this->sameAsFor = $flagId;
    }

    public function cancelSameAs(): void
    {
        $this->sameAsFor = null;
    }

    public function confirmSameAs(): void
    {
        Gate::authorize('manage-quality');
        $flagId = $this->sameAsFor;
        $this->sameAsFor = null;
        if ($flagId !== null) {
            $this->applyResolution($flagId, 'same_as');
        }
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

        // The "same as" confirmation: the flight to hide (the flag's) and the one to keep.
        $dialog = null;
        if ($this->sameAsFor !== null && ($flag = DB::table('quality_flags')->where('id', $this->sameAsFor)->first()) !== null) {
            $hide = DB::table('flights')->where('id', $flag->flight_id)->first();
            $keep = DB::table('flights')->where('id', $flag->related_flight_id)->first();
            $dialog = ['flight' => $hide, 'related' => $keep, 'openCounts' => DB::table('quality_flags')->where('state', 'open')
                ->whereIn('flight_id', [$hide->id, $keep->id])->selectRaw('flight_id, COUNT(*) AS n')->groupBy('flight_id')->pluck('n', 'flight_id')->all()];
        }

        return view('livewire.quality.flag-list', [
            'canAct' => Gate::allows('manage-quality'),
            'dialog' => $dialog,
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
