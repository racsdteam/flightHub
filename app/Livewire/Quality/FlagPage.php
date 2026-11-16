<?php

namespace App\Livewire\Quality;

use App\Livewire\AdminPage;
use App\Quality\FlagResolver;
use App\Quality\FlightRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Title;
use RuntimeException;

/**
 * One flag: details, flight, events; resolutions through FlagResolver
 * (manage-quality; the actor is the signed-in user). "Same as" names the
 * flight that will be HIDDEN and the one KEPT, and asks for confirmation.
 */
#[Title('Quality flag')]
class FlagPage extends AdminPage
{
    public int $flagId;

    public string $note = '';

    public bool $confirmingSameAs = false;

    protected function ability(): string
    {
        return 'view-hub';
    }

    public function mount(int $flag): void
    {
        abort_unless(DB::table('quality_flags')->where('id', $flag)->exists(), 404);
        $this->flagId = $flag;
    }

    public function resolve(string $resolution): void
    {
        Gate::authorize('manage-quality');
        $this->confirmingSameAs = false;
        $value = $resolution === 'none' ? null : $resolution;
        if (! in_array($value, ['fixed', 'ignored', 'reported', null], true)) {
            $this->addError('note', 'Unknown resolution.');

            return;
        }
        if (in_array($value, ['ignored', 'reported'], true) && trim($this->note) === '') {
            $this->addError('note', 'A note is required for '.$value.'.');

            return;
        }
        $this->apply($value);
    }

    public function askSameAs(): void
    {
        Gate::authorize('manage-quality');
        $this->confirmingSameAs = true;
    }

    public function cancelSameAs(): void
    {
        $this->confirmingSameAs = false;
    }

    public function confirmSameAs(): void
    {
        Gate::authorize('manage-quality');
        $this->confirmingSameAs = false;
        $this->apply('same_as');
    }

    private function apply(?string $resolution): void
    {
        try {
            (new FlagResolver)->resolve($this->flagId, $resolution, (string) auth()->user()->email, trim($this->note) === '' ? null : trim($this->note));
            $this->note = '';
            session()->flash('status', $resolution === null ? 'Resolution removed.' : "Resolution set: {$resolution}.");
        } catch (InvalidArgumentException|RuntimeException $e) {
            $this->addError('note', $e->getMessage());
        }
    }

    public function render(): View
    {
        $flag = DB::table('quality_flags')->where('id', $this->flagId)->first();
        abort_if($flag === null, 404);
        $flight = DB::table('flights')->where('id', $flag->flight_id)->first();
        $related = $flag->related_flight_id === null ? null : DB::table('flights')->where('id', $flag->related_flight_id)->first();

        return view('livewire.quality.flag-page', [
            'flag' => $flag,
            'flight' => $flight,
            'related' => $related,
            'details' => $flag->details === null ? [] : json_decode($flag->details, true),
            'events' => DB::table('quality_flag_events')->where('flag_id', $flag->id)->orderByDesc('id')->get(),
            'isDuplicate' => $flag->rule === FlightRules::DUPLICATE_SUSPECTED && $related !== null,
            'canAct' => Gate::allows('manage-quality'),
            // Display only: open flags of the two flights shown in the "same as" confirmation.
            'openCounts' => DB::table('quality_flags')->where('state', 'open')->whereIn('flight_id', array_filter([$flight->id, $related?->id]))
                ->selectRaw('flight_id, COUNT(*) AS n')->groupBy('flight_id')->pluck('n', 'flight_id')->all(),
        ]);
    }
}
