<div wire:poll.60s>
    <div class="page-head">
        <h1>Flights</h1>
        <span class="muted">Kigali day <x-time :at="$window->from" format="Y-m-d" /> · {{ $rows->count() }} flights · refreshes every 60 s</span>
    </div>

    <div class="filters card">
        <div><label for="day">Day (Kigali)</label><input id="day" type="date" wire:model.live="day"></div>
        <div><label for="flow">Flow</label>
            <select id="flow" wire:model.live="flow"><option value="">Both</option><option value="ARR">ARR</option><option value="DEP">DEP</option></select></div>
        <div><label for="status">Status</label>
            <select id="status" wire:model.live="status"><option value="">Any</option>@foreach ($statuses as $s)<option value="{{ $s }}">{{ $s }}</option>@endforeach</select></div>
        <div><label for="airline">Airline</label>
            <select id="airline" wire:model.live="airline"><option value="">Any</option>@foreach ($airlines as $a)<option value="{{ $a }}">{{ $a }}</option>@endforeach</select></div>
        <div><label for="q">Flight or registration</label><input id="q" type="search" wire:model.live.debounce.400ms="q" placeholder="WB711, 9XRWT"></div>
        <label class="check"><input type="checkbox" wire:model.live="flagged"> Has open flags</label>
        <label class="check"><input type="checkbox" wire:model.live="all"> Also removed and same-as flights</label>
    </div>

    <div class="card table-wrap">
        <table class="table">
            <thead><tr>
                <th>Flight</th><th>Flow</th><th>From / to</th><th>Scheduled</th><th>Best estimate</th>
                <th>Actual (off- / in-block)</th><th>Status</th><th>Stand</th><th>Reg</th><th>Type</th><th>Open flags</th>
            </tr></thead>
            <tbody>
            @forelse ($rows as $r)
                <tr wire:key="flight-{{ $r->aodb_id }}" @class(['row-muted' => $r->hidden])>
                    <td><a href="{{ route('flights.show', $r->aodb_id) }}" class="mono">{{ $r->flight }}</a>@if ($r->hidden) <span class="badge">{{ $r->hidden }}</span>@endif</td>
                    <td class="mono">{{ $r->flow }}</td>
                    <td class="mono">{{ $r->other ?? '—' }}</td>
                    <td><x-time :at="$r->scheduled" format="H:i" /></td>
                    <td><x-time :at="$r->estimate" format="H:i" /></td>
                    <td><x-time :at="$r->actual" format="H:i" /></td>
                    <td class="mono">{{ $r->status ?? '—' }}</td>
                    <td class="mono">{{ $r->stand ?? '—' }}</td>
                    <td class="mono">{{ $r->reg ?? '—' }}</td>
                    <td class="mono">{{ $r->type ?? '—' }}</td>
                    <td class="num">@if ($r->flags)<span class="badge badge-open">{{ $r->flags }}</span>@else<span class="muted">0</span>@endif</td>
                </tr>
            @empty
                <tr><td colspan="11" class="muted">No flights for this day and these filters.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
