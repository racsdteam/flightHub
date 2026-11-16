@use('App\Admin\Display')
@php
    $openFlags = $flags->where('state', 'open')->count();
    $route = ['adep' => [$groups['route']['adepIata'] ?? null, $groups['route']['adepIcao'] ?? null], 'ades' => [$groups['route']['adesIata'] ?? null, $groups['route']['adesIcao'] ?? null]];
    // Cards, in the API's groups. Identity keeps only what the page header does not already show;
    // the route card shows the airport codes large instead of as rows.
    $cards = ['identity' => ['Identity', 'tag'], 'route' => ['Route', 'link'], 'schedule' => ['Schedule', 'calendar'], 'times' => ['Times', 'clock'],
        'acdm' => ['A-CDM milestones', 'gauge'], 'resources' => ['Resources', 'layers'], 'aircraft' => ['Aircraft', 'plane'], 'passengers' => ['Passengers', 'users'],
        'delays' => ['Delays', 'clock'], 'ground_handling' => ['Ground handling', 'server']];
    $only = ['identity' => ['flightNumber', 'airlineIata', 'airlineIcao', 'callsign']];
    $skip = ['route' => ['adepIata', 'adepIcao', 'adesIata', 'adesIcao']];
    $kinds = ['first_seen' => 'First seen', 'field_changed' => 'Field changed', 'removed_from_feed' => 'Removed from feed', 'returned_to_feed' => 'Returned to feed'];
@endphp
<div>
    <span class="eyebrow"><a href="{{ route('flights.day', ['day' => \App\Admin\Times::kigali($flight->reference_time, 'Y-m-d')]) }}">Flights</a> › Flight</span>
    <div class="flight-head">
        <h1><x-icon name="plane" size="28" /> {{ $flight->full_flight_number ?? $flight->aodb_id }}</h1>
        <x-chip tone="neutral" :icon="$flight->flow === 'ARR' ? 'arrow-down' : 'arrow-up'">{{ Display::flow($flight->flow) }}</x-chip>
        @if ($route['adep'][0] || $route['ades'][0])
            <span class="route"><span class="mono">{{ $route['adep'][0] ?? '—' }}</span> <x-icon name="arrow-right" size="22" /> <span class="mono">{{ $route['ades'][0] ?? '—' }}</span></span>
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
        <span><span class="k">Reference time</span> <x-time :at="$flight->reference_time" format="H:i" />@if ($flight->reference_time_source) <span class="muted">({{ Display::fieldLabel($flight->reference_time_source) }})</span>@endif</span>
        <span><span class="k">First seen</span> <x-time :at="$flight->first_seen_at" /></span>
        <span><span class="k">Last seen</span> <x-time :at="$flight->last_seen_at" /></span>
        <span><span class="k">Version</span> {{ $flight->current_version_no }}</span>
    </p>

    <div class="flight-grid">
        <div class="flight-cards">
        @foreach ($cards as $group => [$title, $icon])
            @continue(! isset($groups[$group]))
            @php
                $fields = $groups[$group];
                if (isset($only[$group])) { $fields = array_intersect_key($fields, array_flip($only[$group])); }
                if (isset($skip[$group])) { $fields = array_diff_key($fields, array_flip($skip[$group])); }
                $filled = array_filter($fields, fn ($v) => ! Display::isEmpty($v));
                $emptyCount = count($fields) - count($filled);
                $open = (bool) ($showEmpty[$group] ?? false);
                $shown = $open ? $fields : $filled;
            @endphp
            <section class="card" wire:key="card-{{ $group }}">
                <div class="card-head">
                    <h2><x-icon :name="$icon" size="20" /> {{ $title }}</h2>
                    @if ($emptyCount)
                        <button type="button" class="button button-quiet button-xs" wire:click="$set('showEmpty.{{ $group }}', {{ $open ? 'false' : 'true' }})" aria-expanded="{{ $open ? 'true' : 'false' }}">
                            {{ $emptyCount }} empty {{ $emptyCount === 1 ? 'field' : 'fields' }} · {{ $open ? 'Hide' : 'Show' }}
                        </button>
                    @endif
                </div>
                @if ($group === 'route' && ($route['adep'][0] || $route['ades'][0]))
                    <div class="route-big">
                        @foreach (['adep' => ['adepIata', 'adepIcao'], 'ades' => ['adesIata', 'adesIcao']] as $end => [$iata, $icao])
                            @if ($end === 'ades')<x-icon name="arrow-right" size="24" />@endif
                            <span class="airport">
                                <b class="mono">{{ $route[$end][0] ?? '—' }}</b>
                                <span class="small muted mono">{{ $route[$end][1] ?? '' }}</span>
                                @foreach ([$iata, $icao] as $code)
                                    @if ($c = $corrected[$code] ?? null)<span class="small"><span class="struck mono">{{ Display::text($c['aodb']) }}</span><span class="corrected" title="{{ $c['reference'] ?? 'no active reference row' }}">Corrected</span></span>@endif
                                @endforeach
                                <span class="code-label">{{ $iata }} · {{ $icao }}</span>
                            </span>
                        @endforeach
                    </div>
                @endif
                @if ($filled === [] && ! ($group === 'route' && ($route['adep'][0] || $route['ades'][0])))
                    <p class="muted small">No data from the AODB</p>
                @endif
                @if ($shown !== [])
                    <dl class="dl">
                        @foreach ($shown as $path => $value)
                            @foreach (Display::rows($path, $value, $open) as $row)
                                @php $c = $corrected[$row['path']] ?? null; @endphp
                                <dt @class(['nested' => $row['depth'] > 0, 'nested-2' => $row['depth'] > 1])><span title="{{ Display::description($row['path']) }}">{{ $row['label'] }}</span><span class="code-label">{{ $row['path'] }}</span></dt>
                                <dd @class(['nested' => $row['depth'] > 0])>
                                    @if ($c)
                                        <span class="struck mono">{{ Display::text($c['aodb']) }}</span><span class="mono">{{ Display::text($c['served']) }}</span><span class="corrected" title="{{ $c['reference'] ?? 'no active reference row' }}">Corrected</span>
                                    @elseif ($row['kind'] === 'group')
                                        <span class="muted small">{{ count(array_filter(Display::plain($value) ?? [], fn ($v) => ! Display::isEmpty($v))) ? '' : '—' }}</span>
                                    @elseif ($row['kind'] === 'table')
                                        @php $columns = array_keys(array_merge(...$row['value'])); @endphp
                                        <table class="kv mini">
                                            <thead><tr>@foreach ($columns as $col)<th scope="col">{{ Display::fieldLabel($col) }}</th>@endforeach</tr></thead>
                                            <tbody>@foreach ($row['value'] as $item)<tr>@foreach ($columns as $col)<td><x-value :value="$item[$col] ?? null" /></td>@endforeach</tr>@endforeach</tbody>
                                        </table>
                                    @else
                                        <x-value :value="$row['value']" :class="is_string($row['value']) && mb_strlen($row['value']) <= 24 && ! str_contains($row['value'], ' ') ? 'mono' : ''" />
                                    @endif
                                </dd>
                            @endforeach
                        @endforeach
                    </dl>
                @endif
            </section>
        @endforeach
        </div>
        <div class="flight-side">
            <section class="card">
                <div class="card-head"><h2><x-icon name="rotate" size="20" /> Aircraft rotation</h2></div>
                @php $prev = $related[$flight->rotation_prev_flight_id] ?? null; @endphp
                @php $next = $related[$flight->rotation_next_flight_id] ?? null; @endphp
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
                @php $sameAs = $related[$flight->same_as_flight_id] ?? null; @endphp
                @if ($flight->rotation_source || ! Display::isEmpty($groups['rotation']['linkedId'] ?? null) || $sameAs || $sameAsThis->isNotEmpty() || $flags->whereNotNull('related_aodb_id')->isNotEmpty())
                    <dl class="dl unserved">
                        @if ($flight->rotation_source)<dt>Linked by<span class="code-label">hub.rotationSource</span></dt><dd>{{ $flight->rotation_source === 'aodb' ? 'AODB link' : 'Registration' }}</dd>@endif
                        @unless (Display::isEmpty($groups['rotation']['linkedId'] ?? null))<dt>{{ Display::fieldLabel('linkedId') }}<span class="code-label">linkedId</span></dt><dd class="mono">{{ Display::text($groups['rotation']['linkedId']) }}</dd>@endunless
                        @if ($sameAs)<dt>Same as (kept)</dt><dd><a href="{{ route('flights.show', $sameAs->aodb_id) }}" class="mono">{{ $sameAs->full_flight_number }} {{ $sameAs->flow }}</a></dd>@endif
                        @if ($sameAsThis->isNotEmpty())<dt>Hidden as the same as this</dt><dd>@foreach ($sameAsThis as $h)<a href="{{ route('flights.show', $h->aodb_id) }}" class="mono">{{ $h->full_flight_number }} {{ $h->flow }}</a> @endforeach</dd>@endif
                        @foreach ($flags->whereNotNull('related_aodb_id') as $f)
                            <dt class="mono">{{ $f->rule }}</dt><dd><a href="{{ route('flights.show', $f->related_aodb_id) }}" class="mono">{{ $f->related_flight }}</a></dd>
                        @endforeach
                    </dl>
                @endif
            </section>

            <section class="card flags-mini">
                <div class="card-head"><h2><x-icon name="shield" size="20" /> Data-quality flags ({{ $openFlags }} open)</h2></div>
                @forelse ($flags as $f)
                    <div wire:key="flag-{{ $f->id }}" class="flag-row">
                        <p><a href="{{ route('quality.flag', $f->id) }}">{{ $f->rule }}</a>@if ($f->field) · {{ Display::fieldLabel($f->field) }} <span class="code-label">{{ $f->field }}</span>@endif</p>
                        <p class="actions">
                            @if ($f->state === 'open')<x-chip tone="error" icon="alert">Open</x-chip>@else<x-chip tone="ok" icon="check-circle">Cleared</x-chip>@endif
                            @if ($f->resolution)<x-chip tone="info" icon="check">{{ str_replace('_', ' ', $f->resolution) }}</x-chip>@endif
                            <span class="small muted">since <x-time :at="$f->first_detected_at" /></span>
                        </p>
                    </div>
                @empty
                    <x-empty icon="check-circle" title="No quality flags" />
                @endforelse
                @if ($corrections !== [])
                    <h3>Corrections applied</h3>
                    <dl class="dl">
                        @foreach ($corrections as $c)
                            <dt>{{ Display::fieldLabel($c['field']) }}<span class="code-label">{{ $c['field'] }}</span></dt>
                            <dd><span class="struck mono">{{ Display::text($c['aodb']) }}</span><span class="mono">{{ Display::text($c['served']) }}</span><span class="corrected" title="{{ $c['reference'] ?? 'no active reference row' }}">Corrected</span></dd>
                        @endforeach
                    </dl>
                @endif
            </section>
        </div>

    </div>

    @if ($unserved !== [])
        <section class="card">
            <div class="card-head"><h2><x-icon name="info" size="20" /> In the record but never served</h2></div>
            <p class="card-sub">These AODB fields are kept in the record but are in no API field group.</p>
            <dl class="dl">@foreach ($unserved as $path => $value)<dt>{{ Display::fieldLabel($path) }}<span class="code-label">{{ $path }}@if (! $documented($path)) · not in the AODB spec @endif</span></dt><dd><x-value :value="$value" /></dd>@endforeach</dl>
        </section>
    @endif

    <section class="card table-card">
        <div class="card-head padded"><h2><x-icon name="clock" size="20" /> Change history <span class="muted small">(newest first)</span></h2></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th scope="col" class="num">#</th><th scope="col">Observed (Kigali time)</th><th scope="col">Change</th><th scope="col">Field</th><th scope="col">Old value</th><th scope="col">New value</th><th scope="col">Source</th></tr></thead>
                <tbody>
                @foreach ($history as $e)
                    @php $old = Display::decode($e->old_value); @endphp
                    @php $new = Display::decode($e->new_value); @endphp
                    <tr wire:key="event-{{ $e->id }}">
                        <td class="num">{{ $e->id }}</td>
                        <td><x-time :at="$e->observed_at" format="Y-m-d H:i:s" /></td>
                        <td><span title="{{ $e->kind }}">{{ $kinds[$e->kind] ?? $e->kind }}</span></td>
                        <td>@if ($e->field){{ Display::fieldLabel($e->field) }}<span class="sub mono">{{ $e->field }}</span>@else<span class="muted">—</span>@endif</td>
                        @if ($e->kind === 'first_seen')
                            <td></td><td>Whole record</td>
                        @else
                            <td><span @class(['struck' => $e->source === 'correction'])>{{ \Illuminate\Support\Str::limit(Display::isUtcTime($old) ? '' : Display::text($old, $e->field), 120) }}</span>@if (Display::isUtcTime($old))<x-time :at="$old" />@endif</td>
                            <td>@if (Display::isUtcTime($new))<x-time :at="$new" />@else{{ \Illuminate\Support\Str::limit(Display::text($new, $e->field), 120) }}@endif @if ($e->source === 'correction')<span class="corrected">Corrected</span>@endif</td>
                        @endif
                        <td><span class="code code-src">{{ $e->source }}</span>@if ($e->source_ref) <span class="sub mono">{{ $e->source_ref }}</span>@endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        {{ $history->links() }}
    </section>
</div>
