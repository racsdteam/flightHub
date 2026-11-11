@php($show = [\App\Livewire\Flights\FlightPage::class, 'show'])
<div>
    <div class="page-head">
        <h1 class="mono">{{ $flight->full_flight_number ?? $flight->aodb_id }} <span class="muted">{{ $flight->flow }}</span></h1>
        <span>
            @if ($flight->in_feed)<span class="badge badge-good">in feed</span>@else<span class="badge badge-critical">removed from feed</span>@endif
            @if ($flight->same_as_flight_id)<span class="badge badge-warning">same as another flight (hidden from lists)</span>@endif
            <a href="{{ route('flights.day', ['day' => \App\Admin\Times::kigali($flight->reference_time, 'Y-m-d')]) }}">Day view</a>
        </span>
    </div>
    <p class="muted mono small">AODB id {{ $flight->aodb_id }} · reference time <x-time :at="$flight->reference_time" /> ({{ $flight->reference_time_source ?? 'none' }}) · first seen <x-time :at="$flight->first_seen_at" /> · last seen <x-time :at="$flight->last_seen_at" /> · version {{ $flight->current_version_no }}</p>

    <div class="two-col">
        <section class="card section">
            <h2>Served vs AODB</h2>
            @forelse ($corrections as $c)
                <p class="mono"><strong>{{ $c['field'] }}</strong>: <span class="diff-served">{{ $show($c['served']) }}</span> ← <span class="diff-aodb">{{ $show($c['aodb']) }}</span> <span class="muted">{{ $c['reference'] ?? 'no active reference row' }}</span></p>
            @empty
                <p class="muted">No served value differs from the AODB value.</p>
            @endforelse
        </section>

        <section class="card section">
            <h2>Rotation and related flights</h2>
            <dl class="dl">
                <dt>next departure</dt><dd>@if ($n = $related[$flight->rotation_next_flight_id] ?? null)<a href="{{ route('flights.show', $n->aodb_id) }}">{{ $n->full_flight_number }} {{ $n->flow }}</a> <span class="muted">({{ $flight->rotation_source }})</span>@else — @endif</dd>
                <dt>previous arrival</dt><dd>@if ($p = $related[$flight->rotation_prev_flight_id] ?? null)<a href="{{ route('flights.show', $p->aodb_id) }}">{{ $p->full_flight_number }} {{ $p->flow }}</a> <span class="muted">({{ $flight->rotation_source }})</span>@else — @endif</dd>
                <dt>same as</dt><dd>@if ($s = $related[$flight->same_as_flight_id] ?? null)<a href="{{ route('flights.show', $s->aodb_id) }}">{{ $s->full_flight_number }} {{ $s->flow }}</a> (kept)@else — @endif</dd>
                <dt>hidden as same as this</dt><dd>@forelse ($sameAsThis as $h)<a href="{{ route('flights.show', $h->aodb_id) }}">{{ $h->full_flight_number }} {{ $h->flow }}</a> @empty — @endforelse</dd>
                @foreach ($flags->whereNotNull('related_aodb_id') as $f)
                    <dt>{{ $f->rule }}</dt><dd><a href="{{ route('flights.show', $f->related_aodb_id) }}">{{ $f->related_flight }}</a></dd>
                @endforeach
            </dl>
        </section>
    </div>

    <section class="card section">
        <h2>Quality flags</h2>
        @forelse ($flags as $f)
            <div wire:key="flag-{{ $f->id }}" class="section">
                <p>
                    <a href="{{ route('quality.flag', $f->id) }}" class="mono">{{ $f->rule }}@if ($f->field) · {{ $f->field }}@endif</a>
                    <span @class(['badge', 'badge-open' => $f->state === 'open'])>{{ $f->state }}</span>
                    @if ($f->resolution)<span class="badge badge-accent">{{ $f->resolution }}</span>@endif
                </p>
                <p class="small muted">@foreach ($flagEvents[$f->id] ?? [] as $e)<x-time :at="$e->at" /> {{ $e->kind }} {{ $e->from_value }}→{{ $e->to_value }} {{ $e->actor ? '· '.$e->actor : '' }}@if (! $loop->last) · @endif @endforeach</p>
            </div>
        @empty
            <p class="muted">No quality flags.</p>
        @endforelse
    </section>

    <section class="card section">
        <h2>Fields, grouped as the API groups them</h2>
        <div class="two-col">
            @foreach ($groups as $group => $fields)
                <div>
                    <h3>{{ str_replace('_', ' ', $group) }}</h3>
                    <dl class="dl">
                        @foreach ($fields as $path => $value)
                            <dt>{{ $path }}</dt><dd>{{ $show($value) }}</dd>
                        @endforeach
                    </dl>
                </div>
            @endforeach
        </div>
        @if ($unserved !== [])
            <h3>In the record, in no group (never served)</h3>
            <dl class="dl">@foreach ($unserved as $path => $value)<dt>{{ $path }}</dt><dd>{{ $value }} <span class="muted">{{ $documented($path) ? '' : '(not in the API spec)' }}</span></dd>@endforeach</dl>
        @endif
    </section>

    <section class="card section">
        <h2>Change history <span class="muted">(newest first)</span></h2>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>#</th><th>Observed</th><th>Kind</th><th>Field</th><th>Old</th><th>New</th><th>Source</th></tr></thead>
                <tbody>
                @foreach ($history as $e)
                    <tr wire:key="event-{{ $e->id }}">
                        <td class="num">{{ $e->id }}</td>
                        <td><x-time :at="$e->observed_at" format="Y-m-d H:i:s" /></td>
                        <td class="mono">{{ $e->kind }}</td>
                        <td class="mono">{{ $e->field ?? '—' }}</td>
                        <td class="mono small">{{ $e->kind === 'first_seen' ? '' : \Illuminate\Support\Str::limit((string) $e->old_value, 80) }}</td>
                        <td class="mono small">{{ $e->kind === 'first_seen' ? 'whole record' : \Illuminate\Support\Str::limit((string) $e->new_value, 80) }}</td>
                        <td class="mono">{{ $e->source }}@if ($e->source_ref) <span class="muted">{{ $e->source_ref }}</span>@endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        {{ $history->links() }}
    </section>
</div>
