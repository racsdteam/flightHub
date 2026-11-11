<div>
    <div class="page-head"><h1>Quality flags</h1><span class="muted">{{ $flags->total() }} flags</span></div>

    <div class="filters card">
        <div><label for="state">State</label>
            <select id="state" wire:model.live="state"><option value="">Any</option><option value="open">open</option><option value="cleared">cleared</option></select></div>
        <div><label for="resolution">Resolution</label>
            <select id="resolution" wire:model.live="resolution"><option value="">Any</option><option value="none">none</option>@foreach ($resolutions as $r)<option value="{{ $r }}">{{ $r }}</option>@endforeach</select></div>
        <div><label for="rule">Rule</label>
            <select id="rule" wire:model.live="rule"><option value="">Any</option>@foreach ($rules as $r)<option value="{{ $r }}">{{ $r }}</option>@endforeach</select></div>
        <div><label for="day">Day (Kigali)</label><input id="day" type="date" wire:model.live="day"></div>
        <div><label for="flight">Flight</label><input id="flight" type="search" wire:model.live.debounce.400ms="flight" placeholder="KQ470"></div>
    </div>

    <div class="two-col">
        <section class="card section">
            <h2>Per rule</h2>
            <table class="kv">
                @forelse ($counts as $r => $n)<tr><th class="mono">{{ $r }}</th><td class="mono">{{ $n }}</td></tr>@empty<tr><td class="muted">None</td></tr>@endforelse
            </table>
        </section>
    </div>

    <div class="card table-wrap">
        <table class="table">
            <thead><tr><th>Flag</th><th>Flight</th><th>Field</th><th>State</th><th>Resolution</th><th>First detected</th><th>Last detected</th></tr></thead>
            <tbody>
            @forelse ($flags as $f)
                <tr wire:key="flag-{{ $f->id }}">
                    <td><a href="{{ route('quality.flag', $f->id) }}" class="mono">{{ $f->rule }}</a></td>
                    <td><a href="{{ route('flights.show', $f->aodb_id) }}" class="mono">{{ $f->full_flight_number }} {{ $f->flow }}</a> <span class="muted small"><x-time :at="$f->reference_time" format="m-d H:i" /></span></td>
                    <td class="mono">{{ $f->field ?? '—' }}</td>
                    <td><span @class(['badge', 'badge-open' => $f->state === 'open'])>{{ $f->state }}</span></td>
                    <td>@if ($f->resolution)<span class="badge badge-accent">{{ $f->resolution }}</span>@else<span class="muted">—</span>@endif</td>
                    <td><x-time :at="$f->first_detected_at" /></td>
                    <td><x-time :at="$f->last_detected_at" /></td>
                </tr>
            @empty
                <tr><td colspan="7" class="muted">No flags for these filters.</td></tr>
            @endforelse
            </tbody>
        </table>
        {{ $flags->links() }}
    </div>
</div>
