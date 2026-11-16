@use('App\Admin\Display')
<div>
    <div class="page-head">
        <div>
            <span class="eyebrow">Data quality</span>
            <h1>Data quality</h1>
            <p class="page-sub">Quality flags raised by the hub's rules · {{ $flags->total() }} for these filters</p>
        </div>
    </div>

    <div class="rule-tiles">
        <div class="card rule-tile summary">
            <span class="tile-label"><x-icon name="shield" size="20" /></span>
            <p class="tile-value">{{ $openNow }}</p>
            <span>Open flags</span>
            @if ($openTrend > 0)<x-chip tone="error" icon="arrow-up">+{{ $openTrend }} vs 24 h ago</x-chip>
            @elseif ($openTrend < 0)<x-chip tone="ok" icon="arrow-down">{{ $openTrend }} vs 24 h ago</x-chip>
            @else<x-chip tone="disabled" icon="minus">0 vs 24 h ago</x-chip>@endif
        </div>
        <div class="card rule-tile summary">
            <span class="tile-label"><x-icon name="check-circle" size="20" /></span>
            <p class="tile-value">{{ $clearedDay }}</p>
            <span>Cleared in the last 24 h</span>
            @php($d = $clearedDay - $clearedPrev)
            <x-chip :tone="$d === 0 ? 'disabled' : 'neutral'" :icon="$d > 0 ? 'arrow-up' : ($d < 0 ? 'arrow-down' : 'minus')">{{ $d > 0 ? '+' : '' }}{{ $d }} vs previous 24 h</x-chip>
        </div>
        @foreach ($counts as $r => $n)
            <button type="button" class="card rule-tile" wire:click="$set('rule', '{{ $rule === $r ? '' : $r }}')" aria-pressed="{{ $rule === $r ? 'true' : 'false' }}">
                <x-icon :name="Display::RULE_ICONS[$r] ?? 'shield'" size="20" />
                <span class="tile-value">{{ $n }}</span>
                <span class="rule-name">{!! str_replace('_', '_<wbr>', e($r)) !!}</span>
            </button>
        @endforeach
    </div>

    <div class="card filters filters-labelled" role="search" aria-label="Filter quality flags">
        <div class="field"><label for="rule">Rule</label>
            <select id="rule" wire:model.live="rule"><option value="">All rules</option>@foreach ($rules as $r)<option value="{{ $r }}">{{ $r }}</option>@endforeach</select></div>
        <div class="field"><label for="state">State</label>
            <select id="state" wire:model.live="state"><option value="">All (open and cleared)</option><option value="open">Open</option><option value="cleared">Cleared</option></select></div>
        <div class="field"><label for="resolution">Resolution</label>
            <select id="resolution" wire:model.live="resolution"><option value="">All resolutions</option><option value="none">No resolution</option>@foreach ($resolutions as $r)<option value="{{ $r }}">{{ str_replace('_', ' ', $r) }}</option>@endforeach</select></div>
        <div class="field"><label for="day">Day (Kigali)</label>
            <div class="input-icon"><x-icon name="calendar" /><input id="day" type="date" wire:model.live="day"></div></div>
        <div class="field"><label for="flight">Flight</label>
            <div class="input-icon"><x-icon name="search" /><input id="flight" type="search" wire:model.live.debounce.400ms="flight" placeholder="Flight number or AODB id"></div></div>
    </div>

    <div class="card table-card">
        @if ($flags->isEmpty())
            <x-empty icon="check-circle" title="No flags for these filters" hint="Try another state, rule or day." />
        @else
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th scope="col">Rule</th><th scope="col">Flight</th><th scope="col">Field</th><th scope="col">State</th><th scope="col">Resolution</th><th scope="col">Note</th><th scope="col">First detected</th><th scope="col">Last detected</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody>
                @foreach ($flags as $f)
                    <tr wire:key="flag-{{ $f->id }}" @class(['row-error' => $f->state === 'open' && ! $f->resolution])>
                        <td><span class="cell-flight"><x-icon :name="Display::RULE_ICONS[$f->rule] ?? 'shield'" /><a href="{{ route('quality.flag', $f->id) }}" class="mono">{{ $f->rule }}</a></span></td>
                        <td><a href="{{ route('flights.show', $f->aodb_id) }}" class="mono">{{ $f->full_flight_number }} {{ $f->flow }}</a><span class="sub"><x-time :at="$f->reference_time" format="Y-m-d H:i" /></span></td>
                        <td class="mono">{{ $f->field ?? '—' }}</td>
                        <td>@if ($f->state === 'open')<x-chip tone="error" icon="alert">Open</x-chip>@else<x-chip tone="ok" icon="check-circle">Cleared</x-chip>@endif</td>
                        <td>@if ($f->resolution)<x-chip tone="info" icon="check">{{ str_replace('_', ' ', $f->resolution) }}</x-chip>@else<span class="muted">—</span>@endif</td>
                        <td class="small">{{ $f->resolution_note ? \Illuminate\Support\Str::limit($f->resolution_note, 60) : '—' }}</td>
                        <td><x-time :at="$f->first_detected_at" /></td>
                        <td><x-time :at="$f->last_detected_at" /></td>
                        <td><a class="button button-secondary button-xs" href="{{ route('quality.flag', $f->id) }}">@can('manage-quality') Resolve @else View @endcan <x-icon name="arrow-right" size="14" /></a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        {{ $flags->links() }}
        @endif
    </div>
</div>
