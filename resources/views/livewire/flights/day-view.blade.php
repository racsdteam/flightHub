@use('App\Admin\Display')
<div wire:poll.60s>
    <div class="page-head">
        <div>
            <h1>Flights</h1>
            <p class="page-sub">Kigali day <x-time :at="$window->from" format="Y-m-d" /> · refreshes every 60 s</p>
        </div>
    </div>

    <div class="card filters" role="search" aria-label="Filter flights">
        <div class="field">
            <label for="day" class="visually-hidden">Day (Kigali)</label>
            <div class="input-icon"><x-icon name="calendar" /><input id="day" type="date" wire:model.live="day"></div>
        </div>
        <div class="seg" role="group" aria-label="Flow">
            <button type="button" wire:click="$set('flow', '')" aria-pressed="{{ $flow === '' ? 'true' : 'false' }}">All</button>
            <button type="button" wire:click="$set('flow', 'ARR')" aria-pressed="{{ $flow === 'ARR' ? 'true' : 'false' }}">Arrivals</button>
            <button type="button" wire:click="$set('flow', 'DEP')" aria-pressed="{{ $flow === 'DEP' ? 'true' : 'false' }}">Departures</button>
        </div>
        <div class="field">
            <label for="status" class="visually-hidden">Status</label>
            <select id="status" wire:model.live="status"><option value="">All statuses</option>@foreach ($statuses as $s)<option value="{{ $s }}">{{ Display::status($s) }}</option>@endforeach</select>
        </div>
        <div class="field">
            <label for="airline" class="visually-hidden">Airline</label>
            <select id="airline" wire:model.live="airline"><option value="">All airlines</option>@foreach ($airlines as $a)<option value="{{ $a }}">{{ $a }}</option>@endforeach</select>
        </div>
        <label class="switch"><x-icon name="warning" /> Has open flags <input type="checkbox" wire:model.live="flagged"></label>
        <label class="switch">Also removed and same-as <input type="checkbox" wire:model.live="all"></label>
        <div class="field grow">
            <label for="q" class="visually-hidden">Flight number or registration</label>
            <div class="input-icon"><x-icon name="search" /><input id="q" type="search" wire:model.live.debounce.400ms="q" placeholder="Search flight number or registration"></div>
        </div>
        <a class="reset" href="{{ route('flights.day') }}">Reset</a>
    </div>

    <div class="list-bar">
        <p class="muted small" aria-live="polite">@if ($rows->total()) Showing {{ $rows->firstItem() }}–{{ $rows->lastItem() }} of {{ $rows->total() }} flights @else No flights @endif</p>
        <div class="per-page">
            <label for="per-page">Rows per page</label>
            <select id="per-page" wire:model.live="perPage">@foreach ($perPageOptions as $n)<option value="{{ $n }}">{{ $n }}</option>@endforeach</select>
        </div>
    </div>

    <div class="card table-card">
        @if ($rows->isEmpty())
            <x-empty icon="plane" title="No flights found" hint="No flights for this day and these filters. Try another day or reset the filters." />
        @else
        <div class="table-wrap">
            <table class="table">
                <thead><tr>
                    <th scope="col">Flight</th><th scope="col">Direction</th><th scope="col">Other airport</th><th scope="col">Scheduled</th><th scope="col">Best estimate</th>
                    <th scope="col">Actual (off- / in-block)</th><th scope="col">Status</th><th scope="col">Stand</th><th scope="col">Registration</th><th scope="col">Aircraft type</th><th scope="col">Open flags</th>
                </tr></thead>
                <tbody>
                @foreach ($rows as $r)
                    <tr wire:key="flight-{{ $r->aodb_id }}" @class(['row-muted' => $r->hidden])>
                        <td><span class="cell-flight">@if ($r->airline)<span class="code airline" title="Airline code">{{ $r->airline }}</span>@endif<a href="{{ route('flights.show', $r->aodb_id) }}">{{ $r->flight }}</a></span>
                            @if ($r->hidden)<span class="sub">{{ $r->hidden }}</span>@endif</td>
                        <td><span class="dir"><x-icon :name="$r->flow === 'ARR' ? 'arrow-down' : 'arrow-up'" /> {{ Display::flow($r->flow) }}</span></td>
                        <td>@if ($r->other)<span class="code">{{ $r->other }}</span>@else<span class="muted">—</span>@endif</td>
                        <td><x-time :at="$r->scheduled" format="H:i" /></td>
                        <td><x-time :at="$r->estimate" format="H:i" /></td>
                        <td><x-time :at="$r->actual" format="H:i" /></td>
                        <td><x-flight-status :status="$r->status" :milestone="$r->milestone" /></td>
                        <td>@if ($r->stand)<span class="code">{{ $r->stand }}</span>@else<span class="muted">—</span>@endif</td>
                        <td>@if ($r->reg)<span class="code">{{ $r->reg }}</span>@else<span class="muted">—</span>@endif</td>
                        <td class="mono">{{ $r->type ?? '—' }}</td>
                        <td>@if ($r->flags)<span class="count-chip"><x-icon name="warning" size="14" /> {{ $r->flags }}<span class="visually-hidden"> open flags</span></span>@else<span class="muted">—</span>@endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        {{ $rows->links() }}
        @endif
    </div>
</div>
