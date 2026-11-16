<?php

namespace App\Livewire\Flights;

use App\Aodb\AodbSpec;
use App\Aodb\CanonicalJson;
use App\Api\FieldSet;
use App\Api\FlightPresenter;
use App\Livewire\AdminPage;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\WithPagination;

/**
 * One flight: served values beside AODB values where a correction applies,
 * every field grouped as the API groups them, rotation and related flights,
 * quality flags with their events, and the change history (newest first).
 */
#[Title('Flight')]
class FlightPage extends AdminPage
{
    use WithPagination;

    public string $aodbId;

    protected function ability(): string
    {
        return 'view-hub';
    }

    public function mount(string $aodbId): void
    {
        abort_unless(DB::table('flights')->where('aodb_id', $aodbId)->exists(), 404);
        $this->aodbId = $aodbId;
    }

    public function render(): View
    {
        $flight = DB::table('flights')->where('aodb_id', $this->aodbId)->first();
        abort_if($flight === null, 404);

        // The API's own representation, with every group: served values, hub fields, corrections.
        $presented = FlightPresenter::for(FieldSet::forGroups(array_keys(config('api_fields.groups'))), collect([$flight]))->flight($flight);
        $groups = [];
        foreach (config('api_fields.groups') as $group => $paths) {
            foreach ($paths as $path) {
                $name = str_ends_with($path, '.*') ? substr($path, 0, -2) : $path;
                $groups[$group][$path] = str_starts_with($name, 'hub.')
                    ? ($presented['hub']->{substr($name, 4)} ?? null)
                    : ($presented[$name] ?? null);
            }
        }

        // Record paths in no group: kept in the record, never served by the API.
        $all = FieldSet::forGroups(array_keys(config('api_fields.groups')));
        $record = CanonicalJson::decode($flight->record);
        $unserved = array_filter(CanonicalJson::leaves($record), fn ($path) => ! $all->allows($path), ARRAY_FILTER_USE_KEY);

        $related = DB::table('flights')->whereIn('id', array_filter([$flight->same_as_flight_id, $flight->rotation_next_flight_id, $flight->rotation_prev_flight_id]))
            ->get(['id', 'aodb_id', 'full_flight_number', 'flow', 'reference_time', 'adep_iata', 'ades_iata', 'status', 'milestone', 'in_feed'])->keyBy('id');
        $sameAsThis = DB::table('flights')->where('same_as_flight_id', $flight->id)->get(['aodb_id', 'full_flight_number', 'flow']);

        $flags = DB::table('quality_flags as q')->leftJoin('flights as r', 'r.id', '=', 'q.related_flight_id')
            ->where('q.flight_id', $flight->id)->orderByRaw("q.state = 'open' DESC")->orderBy('q.rule')
            ->get(['q.*', 'r.aodb_id as related_aodb_id', 'r.full_flight_number as related_flight']);
        $flagEvents = DB::table('quality_flag_events')->whereIn('flag_id', $flags->pluck('id')->all())->orderBy('id')->get()->groupBy('flag_id');

        $corrections = $presented['hub']->corrections;

        return view('livewire.flights.flight-page', [
            'flight' => $flight,
            'groups' => $groups,
            'corrections' => $corrections,
            // Display only: corrected paths, for the "Corrected" badges beside served values.
            'corrected' => collect($corrections)->keyBy('field')->all(),
            'unserved' => $unserved,
            'documented' => fn (string $path) => AodbSpec::isDocumented($path),
            'related' => $related,
            'sameAsThis' => $sameAsThis,
            'flags' => $flags,
            'flagEvents' => $flagEvents,
            'history' => DB::table('change_events')->where('flight_id', $flight->id)->orderByDesc('id')->paginate(50, pageName: 'history'),
        ]);
    }

    public static function show(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };
    }
}
