<?php

namespace App\Livewire\Quality;

use App\Admin\Audit;
use App\Admin\FlagCounts;
use App\Flights\Window;
use App\Livewire\AdminPage;
use App\Quality\FlagResolver;
use App\Quality\FlightRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * Quality flags: filters (state, resolution, rule, Kigali day, flight) and counts per rule;
 * each row can be resolved here as on the flag page (ResolvesFlags: manage-quality, a note
 * for ignored and reported, "same as" after the same confirmation dialog).
 *
 * Bulk resolution (D-057): with a rule selected, every unresolved flag matching the filters
 * (all pages, at most 500) gets one resolution (fixed, ignored or reported; never same_as) and
 * one note, after a confirmation. Each flag goes through FlagResolver, so each keeps its own
 * event and audit line; one more audit line records the bulk action. Flags resolved by someone
 * else meanwhile are skipped and counted.
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

    public const BULK_LIMIT = 500;

    private const BULK_RESOLUTIONS = ['fixed', 'ignored', 'reported'];

    /** The open bulk confirmation: its resolution and the flags it covers, fixed when asked. */
    #[Locked]
    public ?string $bulkResolution = null;

    /** @var list<int> */
    #[Locked]
    public array $bulkIds = [];

    public string $bulkNote = '';

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
            [$this->bulkResolution, $this->bulkIds, $this->bulkNote] = [null, [], ''];
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

    /**
     * Bulk: every unresolved flag matching the filters, a rule selected (all pages).
     */
    private function bulkQuery(): Builder
    {
        return $this->filtered(true)->whereNull('q.resolution');
    }

    public function askBulk(string $resolution): void
    {
        Gate::authorize('manage-quality');
        abort_unless(in_array($resolution, self::BULK_RESOLUTIONS, true) && $this->rule !== '', 422);
        $this->resetErrorBag();
        [$this->noteFor, $this->noteResolution, $this->sameAsFor] = [null, null, null];

        $ids = $this->bulkQuery()->orderBy('q.id')->limit(self::BULK_LIMIT + 1)->pluck('q.id')->map(fn ($id) => (int) $id)->all();
        if (count($ids) > self::BULK_LIMIT) {
            $this->addError('bulk', 'More than '.self::BULK_LIMIT.' flags: narrow the filters (a day, a state or a flight) to resolve at most '.self::BULK_LIMIT.' at a time.');

            return;
        }
        if ($ids === []) {
            $this->addError('bulk', 'No unresolved flag matches these filters.');

            return;
        }
        [$this->bulkResolution, $this->bulkIds, $this->bulkNote] = [$resolution, $ids, ''];
    }

    public function cancelBulk(): void
    {
        [$this->bulkResolution, $this->bulkIds, $this->bulkNote] = [null, [], ''];
        $this->resetErrorBag();
    }

    public function confirmBulk(): void
    {
        Gate::authorize('manage-quality');
        $resolution = $this->bulkResolution;
        abort_unless(in_array($resolution, self::BULK_RESOLUTIONS, true) && $this->rule !== ''
            && $this->bulkIds !== [] && count($this->bulkIds) <= self::BULK_LIMIT, 422);
        $note = trim($this->bulkNote);
        if (in_array($resolution, ['ignored', 'reported'], true) && $note === '') {
            $this->addError('bulkNote', 'A note is required for '.$resolution.'.');

            return;
        }
        if (mb_strlen($note) > 500) {
            $this->addError('bulkNote', 'Note is at most 500 characters.');

            return;
        }

        $actor = (string) auth()->user()->email;
        $resolver = new FlagResolver;
        $resolved = 0;
        $skipped = 0;
        foreach ($this->bulkIds as $id) {
            $done = DB::transaction(function () use ($id, $resolution, $actor, $note, $resolver) {
                // Locked, then checked: a flag resolved by someone else meanwhile (or no longer
                // of this rule) is skipped, never overwritten.
                $flag = DB::table('quality_flags')->where('id', $id)->lockForUpdate()->first();
                if ($flag === null || $flag->resolution !== null || $flag->rule !== $this->rule) {
                    return false;
                }
                $resolver->resolve($id, $resolution, $actor, $note === '' ? null : $note);

                return true;
            });
            $done ? $resolved++ : $skipped++;
        }

        Audit::record('quality.bulk_resolved', [
            'rule' => $this->rule,
            'filters' => [
                'state' => $this->state === '' ? 'all' : $this->state,
                'resolution' => $this->resolution === '' ? 'all' : $this->resolution,
                'day' => $this->day === '' ? null : $this->day,
                'flight' => trim($this->flight) === '' ? null : trim($this->flight),
            ],
            'resolution' => $resolution,
            'note' => $note === '' ? null : $note,
            'count' => $resolved,
            'skipped' => $skipped,
        ], actor: $actor);

        [$this->bulkResolution, $this->bulkIds, $this->bulkNote] = [null, [], ''];
        session()->flash('status', "Resolved {$resolved} ".Str::plural('flag', $resolved)." as {$resolution}"
            .($skipped > 0 ? "; {$skipped} skipped (resolved by someone else meanwhile)" : '').'.');
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

        $canAct = Gate::allows('manage-quality');

        return view('livewire.quality.flag-list', [
            'canAct' => $canAct,
            'dialog' => $dialog,
            'bulkCount' => $canAct && $this->rule !== '' ? $this->bulkQuery()->count() : 0,
            'bulkLimit' => self::BULK_LIMIT,
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
