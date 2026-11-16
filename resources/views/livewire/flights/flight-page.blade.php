@use('App\Admin\Display')
@php
    $show = [\App\Livewire\Flights\FlightPage::class, 'show'];
    $openFlags = $flags->where('state', 'open')->count();
    $route = [$groups['route']['adepIata'] ?? null, $groups['route']['adesIata'] ?? null];
    $titles = ['identity' => ['Identity', 'tag'], 'route' => ['Route', 'link'], 'schedule' => ['Schedule', 'calendar'], 'times' => ['Times', 'clock'],
        'acdm' => ['A-CDM milestones', 'gauge'], 'resources' => ['Resources', 'layers'], 'aircraft' => ['Aircraft', 'plane'], 'passengers' => ['Passengers', 'users'],
        'delays' => ['Delays', 'clock'], 'ground_handling' => ['Ground handling', 'server']];
@endphp
<div>
    <span class="eyebrow"><a href="{{ route('flights.day', ['day' => \App\Admin\Times::kigali($flight->reference_time, 'Y-m-d')]) }}">Flights</a> › Flight</span>
    <div class="flight-head">
        <h1><x-icon name="plane" size="28" /> {{ $flight->full_flight_number ?? $flight->aodb_id }}</h1>
        <x-chip tone="neutral" :icon="$flight->flow === 'ARR' ? 'arrow-down' : 'arrow-up'">{{ Display::flow($flight->flow) }}</x-chip>
        @if ($route[0] || $route[1])
            <span class="route"><span class="mono">{{ $route[0] ?? '—' }}</span> <x-icon name="arrow-right" size="22" /> <span class="mono">{{ $route[1] ?? '—' }}</span></span>
        @endif
        <x-flight-status :status="$flight->status" :milestone="$flight->milestone" />
        <span class="sep" aria-hidden="true"></span>
        @if ($openFlags)<x-chip tone="error" icon="warning">{{ $openFlags }} open {{ $openFlags === 1 ? 'flag' : 'flags' }}</x-chip>@else<x-chip tone="ok" icon="check">No open flags</x-chip>@endif
        @if ($flight->in_feed)<x-chip tone="ok" icon="check-circle">In feed</x-chip>@else<x-chip tone="error" icon="x-circle">Removed from feed</x-chip>@endif
        @if ($flight->same_as_flight_id)<x-chip tone="warning" icon="duplicate">Same as another flight (hidden from lists)</x-chip>@endif
    </div>
    <p class="meta-line">
        <span><x-icon name="calendar" /> <x-time :at="$flight->reference_time" format="D, Y-m-d" /></span>
        <span><span class="k">AODB id</span> <span class="mono">{{ $flight->aodb_id }}</span></span>
        <span><span class="k">Reference time</span> <x-time :at="$flight->reference_time" format="H:i" /> <span class="muted">({{ $flight->reference_time_source ?? 'none' }})</span></span>
        <span><span class="k">First seen</span> <x-time :at="$flight->first_seen_at" /></span>
        <span><span class="k">Last seen</span> <x-time :at="$flight->last_seen_at" /></span>
        <span><span class="k">Version</span> {{ $flight->current_version_no }}</span>
    </p>

    <div class="flight-grid">
        <div class="flight-side">
            <section class="card">
                <div class="card-head"><h2><x-icon name="rotate" size="20" /> Aircraft rotation</h2></div>
                @php($prev = $related[$flight->rotation_prev_flight_id] ?? null)
                @php($next = $related[$flight->rotation_next_flight_id] ?? null)
                @foreach ([['Previous arrival', $prev], ['This flight', $flight], ['Next departure', $next]] as [$label, $f])
                    <div @class(['rot', 'rot-current' => $f === $flight])>
                        <span class="rot-label">{{ $label }}</span>
                        <span class="rot-main">
                            @if ($f)
                                @if ($f === $flight)<b>{{ $f->full_flight_number }}</b>@else<a href="{{ route('flights.show', $f->aodb_id) }}"><b>{{ $f->full_flight_number }}</b></a>@endif
                                <span class="mono">{{ $f->adep_iata ?? '—' }} → {{ $f->ades_iata ?? '—' }}</span><br>
                                <x-time :at="$f->reference_time" />
                            @else
                                <span class="muted">None linked</span>
                            @endif
                        </span>
                        @if ($f)<x-chip :tone="Display::statusTone($f->status)[0]">{{ Display::status($f->status) }}</x-chip>@endif
                    </div>
                @endforeach
                <dl class="dl unserved">
                    <dt>Rotation source</dt><dd class="mono">{{ $flight->rotation_source ?? '—' }}</dd>
                    <dt>linkedId</dt><dd class="mono">{{ $show($groups['rotation']['linkedId'] ?? null) }}</dd>
                    <dt>Same as (kept)</dt><dd>@if ($s = $related[$flight->same_as_flight_id] ?? null)<a href="{{ route('flights.show', $s->aodb_id) }}" class="mono">{{ $s->full_flight_number }} {{ $s->flow }}</a>@else — @endif</dd>
                    <dt>Hidden as same as this</dt><dd>@forelse ($sameAsThis as $h)<a href="{{ route('flights.show', $h->aodb_id) }}" class="mono">{{ $h->full_flight_number }} {{ $h->flow }}</a> @empty — @endforelse</dd>
                    @foreach ($flags->whereNotNull('related_aodb_id') as $f)
                        <dt class="mono">{{ $f->rule }}</dt><dd><a href="{{ route('flights.show', $f->related_aodb_id) }}" class="mono">{{ $f->related_flight }}</a></dd>
                    @endforeach
                </dl>
            </section>

            <section class="card flags-mini">
                <div class="card-head"><h2><x-icon name="shield" size="20" /> Data-quality flags ({{ $openFlags }} open)</h2></div>
                @forelse ($flags as $f)
                    <div wire:key="flag-{{ $f->id }}" class="flag-row">
                        <p><a href="{{ route('quality.flag', $f->id) }}">{{ $f->rule }}</a>@if ($f->field) <span class="mono muted">· {{ $f->field }}</span>@endif</p>
                        <p class="actions">
                            @if ($f->state === 'open')<x-chip tone="error" icon="alert">Open</x-chip>@else<x-chip tone="ok" icon="check-circle">Cleared</x-chip>@endif
                            @if ($f->resolution)<x-chip tone="info" icon="check">{{ str_replace('_', ' ', $f->resolution) }}</x-chip>@endif
                        </p>
                        <p class="small muted">@foreach ($flagEvents[$f->id] ?? [] as $e)<x-time :at="$e->at" /> {{ $e->kind }} {{ $e->from_value }}→{{ $e->to_value }} {{ $e->actor ? '· '.$e->actor : '' }}@if (! $loop->last) · @endif @endforeach</p>
                    </div>
                @empty
                    <x-empty icon="check-circle" title="No quality flags" />
                @endforelse
                @if ($corrections !== [])
                    <h3>Corrections applied</h3>
                    <dl class="dl">
                        @foreach ($corrections as $c)
                            <dt class="mono">{{ $c['field'] }}</dt>
                            <dd><span class="struck mono">{{ $show($c['aodb']) }}</span><span class="mono">{{ $show($c['served']) }}</span><span class="corrected" title="{{ $c['reference'] ?? 'no active reference row' }}">Corrected</span></dd>
                        @endforeach
                    </dl>
                @endif
            </section>
        </div>

        @foreach ($titles as $group => [$title, $icon])
            @continue(! isset($groups[$group]))
            <section class="card">
                <div class="card-head"><h2><x-icon :name="$icon" size="20" /> {{ $title }}</h2></div>
                @if ($group === 'route' && ($route[0] || $route[1]))
                    <div class="route-big">
                        <span class="airport"><b class="mono">{{ $route[0] ?? '—' }}</b><span class="small muted">{{ $groups['route']['adepIcao'] ?? '' }}</span></span>
                        <x-icon name="arrow-right" size="24" />
                        <span class="airport"><b class="mono">{{ $route[1] ?? '—' }}</b><span class="small muted">{{ $groups['route']['adesIcao'] ?? '' }}</span></span>
                    </div>
                @endif
                <dl class="dl">
                    @foreach ($groups[$group] as $path => $value)
                        @php($name = str_ends_with($path, '.*') ? substr($path, 0, -2) : $path)
                        @php($c = $corrected[$name] ?? null)
                        <dt>{{ Display::fieldLabel($path) }}<span class="code-label">{{ $path }}</span></dt>
                        <dd>
                            @if ($c)
                                <span class="struck mono">{{ $show($c['aodb']) }}</span><span class="mono">{{ $show($value) }}</span><span class="corrected" title="{{ $c['reference'] ?? 'no active reference row' }}">Corrected</span>
                            @elseif (Display::isUtcTime($value))
                                <x-time :at="$value" />
                            @elseif (is_array($value) || is_object($value))
                                <span class="value-long">{{ $show($value) }}</span>
                            @else
                                <span @class(['mono' => is_string($value) && mb_strlen($value) < 40, 'muted' => $value === null])>{{ $show($value) }}</span>
                            @endif
                            @if (str_ends_with($path, '.*'))
                                @foreach ($corrected as $field => $sub)
                                    @if (str_starts_with($field, $name.'.'))
                                        <span class="code-label">{{ $field }}: <span class="struck">{{ $show($sub['aodb']) }}</span> {{ $show($sub['served']) }} <span class="corrected" title="{{ $sub['reference'] ?? 'no active reference row' }}">Corrected</span></span>
                                    @endif
                                @endforeach
                            @endif
                        </dd>
                    @endforeach
                </dl>
            </section>
        @endforeach
    </div>

    @if ($unserved !== [])
        <section class="card">
            <div class="card-head"><h2><x-icon name="info" size="20" /> In the record, in no group (never served)</h2></div>
            <dl class="dl">@foreach ($unserved as $path => $value)<dt class="mono">{{ $path }}</dt><dd><span class="value-long">{{ $value }}</span> <span class="muted">{{ $documented($path) ? '' : '(not in the API spec)' }}</span></dd>@endforeach</dl>
        </section>
    @endif

    <section class="card table-card">
        <div class="card-head padded"><h2><x-icon name="clock" size="20" /> Change history <span class="muted small">(newest first)</span></h2></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th scope="col" class="num">#</th><th scope="col">Observed (Kigali time)</th><th scope="col">Kind</th><th scope="col">Field</th><th scope="col">Old value</th><th scope="col">New value</th><th scope="col">Source</th></tr></thead>
                <tbody>
                @foreach ($history as $e)
                    <tr wire:key="event-{{ $e->id }}">
                        <td class="num">{{ $e->id }}</td>
                        <td><x-time :at="$e->observed_at" format="Y-m-d H:i:s" /></td>
                        <td class="mono">{{ $e->kind }}</td>
                        <td class="mono">{{ $e->field ?? '—' }}</td>
                        <td class="value-long">@if ($e->kind !== 'first_seen')<span @class(['struck' => $e->source === 'correction'])>{{ \Illuminate\Support\Str::limit((string) $e->old_value, 80) }}</span>@endif</td>
                        <td class="value-long">{{ $e->kind === 'first_seen' ? 'whole record' : \Illuminate\Support\Str::limit((string) $e->new_value, 80) }}@if ($e->source === 'correction')<span class="corrected">Corrected</span>@endif</td>
                        <td><span class="code code-src">{{ $e->source }}</span>@if ($e->source_ref) <span class="sub mono">{{ $e->source_ref }}</span>@endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        {{ $history->links() }}
    </section>
</div>
