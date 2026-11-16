@use('App\Admin\Display')
@use('App\Admin\Times')
@php
    $ok = $feed['feed'] === 'ok';
    // Chart geometry (SVG units; sizes are attributes, CSP: no style).
    [$w, $h, $left, $top, $bottom] = [340, 170, 26, 8, 144];
    $perHour = $expected ? $expected / 24 : 0;
    $max = max(1, (int) ceil($perHour), ...array_map(fn ($b) => array_sum($b), $buckets));
    $slot = ($w - $left - 6) / 24;
    $y = fn (float $n) => $bottom - ($bottom - $top) * $n / $max;
@endphp
<div wire:poll.60s>
    <div class="page-head">
        <div>
            <span class="eyebrow">Feed health</span>
            <h1>Dashboard — <span @class(['state-word', 'text-ok' => $ok, 'text-stale' => ! $ok])>{{ $ok ? 'OK' : 'STALE' }}</span></h1>
        </div>
    </div>

    @if ($ok)
        <div class="banner banner-ok banner-lg" role="status">
            <span class="banner-icon"><x-icon name="check-circle" size="36" /></span>
            <p><span class="banner-title">Feed state: OK</span>
            Polls are succeeding. Last successful poll {{ Times::age($lastOk->finished_at) }} (<x-time :at="$lastOk->finished_at" format="H:i:s" /> Kigali time).</p>
        </div>
    @else
        {{-- Never dismissible. --}}
        <div class="banner banner-stale banner-lg" role="alert">
            <span class="banner-icon"><x-icon name="warning" size="36" /></span>
            <p><span class="banner-title">Feed state: STALE</span>
            Feed stale: @if ($lastOk) no successful poll for {{ $dataAge }} (last good poll: <x-time :at="$lastOk->finished_at" format="Y-m-d H:i:s" />). @else no successful poll yet. @endif
            Data shown may be out of date.</p>
        </div>
    @endif

    <div class="grid grid-tiles">
        <section @class(['card', 'tile', 'tile-state-ok' => $ok, 'tile-state-stale' => ! $ok])>
            <h2 class="tile-label"><x-icon name="pulse" /> Feed</h2>
            @if ($ok)<x-chip tone="ok" icon="check-circle" class="chip-lg">OK</x-chip>@else<x-chip tone="stale" icon="warning" class="chip-lg">STALE</x-chip>@endif
            <p class="tile-sub">Stale after 15 minutes without a good poll.</p>
        </section>

        <section class="card tile">
            <h2 class="tile-label"><x-icon name="calendar" /> Last successful poll</h2>
            @if ($lastOk)
                <p class="tile-value mono"><x-time :at="$lastOk->finished_at" format="H:i:s" /></p>
                <p class="tile-sub"><x-time :at="$lastOk->finished_at" format="Y-m-d" /> · {{ Times::age($lastOk->finished_at) }}</p>
            @else
                <p class="tile-value">—</p><p class="tile-sub">No good poll yet.</p>
            @endif
        </section>

        <section class="card tile">
            <h2 class="tile-label"><x-icon name="clock" /> Data age</h2>
            <p class="tile-value">{{ $dataAge ?? '—' }}</p>
            <p class="tile-sub">Since the last successful poll</p>
        </section>

        <section class="card tile">
            <h2 class="tile-label"><x-icon name="server" /> Last poll</h2>
            @if ($last)
                @php([$tone, $icon, $word] = Display::pollChip($last->outcome))
                <span><x-chip :tone="$tone" :icon="$icon">{{ $word }}</x-chip> <span class="code">{{ $last->outcome }}</span></span>
                <p class="tile-sub">HTTP {{ $last->http_status ?? '—' }} · <x-time :at="$last->finished_at" format="H:i:s" /></p>
                @if ($last->error_summary)<p class="tile-sub mono">{{ $last->error_summary }}</p>@endif
            @else
                <p class="tile-value">—</p><p class="tile-sub">No poll yet.</p>
            @endif
        </section>

        <section class="card tile">
            <h2 class="tile-label"><x-icon name="pause" /> AODB pauses / back-off</h2>
            @forelse ($pauses as $label => $until)
                <x-chip tone="warning" icon="pause">{{ $label }}</x-chip>
                <p class="tile-sub">until <x-time :at="$until" format="H:i" /></p>
            @empty
                <span><x-chip tone="disabled" icon="minus">None</x-chip></span>
                <p class="tile-sub">No pause or back-off active.</p>
            @endforelse
        </section>

        <section class="card tile">
            <h2 class="tile-label"><x-icon name="chart" /> Polls, last 24 h</h2>
            <p class="tile-value">{{ array_sum($pollTotals) }}</p>
            <p class="tile-sub">{{ $expected ? '(expected '.$expected.')' : '(poll schedule not readable)' }}</p>
        </section>
    </div>

    <div class="grid grid-3">
        <section class="card">
            <div class="card-head">
                <h2><x-icon name="plane" size="20" /> Today's flights</h2>
                <span class="code">Source: AODB</span>
            </div>
            <p class="card-sub">Kigali day <x-time :at="$day->from" format="Y-m-d" /> · in feed, not hidden as "same as"</p>
            <div class="split">
                <div><span class="big-number">{{ $total }}</span><span class="label">Total flights</span></div>
                <span class="divider"></span>
                <div><span class="figure"><x-icon name="arrow-down" class="text-ok" /> {{ $byFlow['ARR'] }}</span><span class="label">Arrivals (ARR)</span></div>
                <div><span class="figure"><x-icon name="arrow-up" /> {{ $byFlow['DEP'] }}</span><span class="label">Departures (DEP)</span></div>
            </div>
            <h3>By status</h3>
            <ul class="status-list">
                @foreach ($byStatus as $status => $n)
                    @php([$tone, $icon] = Display::statusTone($status))
                    <li><x-icon :name="$icon" :class="\Illuminate\Support\Arr::toCssClasses(['text-ok' => $tone === 'ok', 'text-error' => $tone === 'error', 'text-warning' => $tone === 'warning'])" /> {{ Display::status($status) }} <span class="count">{{ $n }}</span></li>
                @endforeach
            </ul>
        </section>

        <section class="card">
            <div class="card-head">
                <h2><x-icon name="shield" size="20" /> Open data-quality problems</h2>
            </div>
            <div class="split">
                <div><span class="big-number">{{ $openNow }}</span><span class="label">Open quality flags</span></div>
                <div class="push">
                    @if ($openTrend > 0)<x-chip tone="error" icon="arrow-up">+{{ $openTrend }}</x-chip>
                    @elseif ($openTrend < 0)<x-chip tone="ok" icon="arrow-down">{{ $openTrend }}</x-chip>
                    @else<x-chip tone="disabled" icon="minus">0</x-chip>@endif
                    <span class="label">vs 24 h ago</span>
                </div>
            </div>
            @if ($flags === [])
                <x-empty icon="check-circle" title="No open problems" />
            @else
                <table class="kv">
                    <thead><tr><th scope="col">Rule</th><th scope="col" class="num">Count</th></tr></thead>
                    <tbody>
                    @foreach ($flags as $rule => $n)<tr><td class="mono"><a href="{{ route('quality.flags', ['rule' => $rule]) }}">{{ $rule }}</a></td><td class="num">{{ $n }}</td></tr>@endforeach
                    </tbody>
                </table>
            @endif
            <p class="card-foot"><a class="link-arrow" href="{{ route('quality.flags') }}">View all data-quality flags <x-icon name="arrow-right" /></a></p>
        </section>

        <div class="stack">
            <section class="card">
                <div class="card-head">
                    <h2><x-icon name="chart" size="20" /> Polls, last 24 h</h2>
                    @if ($expected)<x-chip :tone="$pollTotals['ok'] >= $expected * 0.95 ? 'ok' : 'error'" :icon="$pollTotals['ok'] >= $expected * 0.95 ? 'check' : 'warning'">{{ $pollTotals['ok'] }} / {{ $expected }} ok</x-chip>@endif
                </div>
                <svg class="chart" viewBox="0 0 {{ $w }} {{ $h }}" width="{{ $w }}" height="{{ $h }}" role="img" aria-labelledby="poll-chart-title">
                    <title id="poll-chart-title">Polls per hour over the last 24 hours: {{ $pollTotals['ok'] }} successful, {{ $pollTotals['failed'] }} failed, {{ $pollTotals['paused'] }} paused{{ $expected ? '; expected '.$expected : '' }}.</title>
                    @foreach ([0, $max / 2, $max] as $tick)
                        <line class="grid-line" x1="{{ $left }}" x2="{{ $w - 4 }}" y1="{{ $y($tick) }}" y2="{{ $y($tick) }}" />
                        <text class="tick" x="{{ $left - 6 }}" y="{{ $y($tick) + 4 }}" text-anchor="end">{{ rtrim(rtrim(number_format($tick, 1), '0'), '.') }}</text>
                    @endforeach
                    @if ($perHour > 0)<line class="expected" x1="{{ $left }}" x2="{{ $w - 4 }}" y1="{{ $y($perHour) }}" y2="{{ $y($perHour) }}" />@endif
                    @foreach ($buckets as $i => $b)
                        @php($x = $left + $i * $slot + $slot * 0.15)
                        @php($bw = $slot * 0.7)
                        @php($from = $since->addHours($i))
                        <g>
                            <title>{{ Times::kigali($from, 'H:i') }}–{{ Times::kigali($from->addHour(), 'H:i') }}: {{ $b['ok'] }} ok, {{ $b['failed'] }} failed, {{ $b['paused'] }} paused</title>
                            @php($base = 0)
                            @foreach (['ok', 'paused', 'failed'] as $kind)
                                @if ($b[$kind] > 0)
                                    <rect class="bar-{{ $kind }}" x="{{ round($x, 1) }}" width="{{ round($bw, 1) }}" y="{{ round($y($base + $b[$kind]), 1) }}" height="{{ round($y($base) - $y($base + $b[$kind]), 1) }}" />
                                    @php($base += $b[$kind])
                                @endif
                            @endforeach
                        </g>
                        @if ($i % 4 === 0)<text class="tick" x="{{ round($x + $bw / 2, 1) }}" y="{{ $bottom + 18 }}" text-anchor="middle">{{ Times::kigali($from, 'H:i') }}</text>@endif
                    @endforeach
                    <line class="axis" x1="{{ $left }}" x2="{{ $w - 4 }}" y1="{{ $bottom }}" y2="{{ $bottom }}" />
                </svg>
                <ul class="legend">
                    <li><span class="swatch swatch-ok"></span><x-icon name="check" size="14" /> Successful ({{ $pollTotals['ok'] }})</li>
                    <li><span class="swatch swatch-failed"></span><x-icon name="x" size="14" /> Failed ({{ $pollTotals['failed'] }})</li>
                    <li><span class="swatch swatch-paused"></span><x-icon name="pause" size="14" /> Paused ({{ $pollTotals['paused'] }})</li>
                    @if ($perHour > 0)<li><span class="swatch swatch-expected"></span> Expected per hour</li>@endif
                </ul>
            </section>

            <section class="card">
                <div class="card-head"><h2><x-icon name="warning" size="20" /> Last poll warnings</h2></div>
                <div>
                    @if (! $ok)
                        <div class="incident incident-stale"><x-icon name="warning" size="20" /><div>
                            <p class="incident-title">Feed stale</p>
                            <p>{{ $lastOk ? 'No successful poll for '.$dataAge.'.' : 'No successful poll yet.' }}</p>
                        </div></div>
                    @endif
                    @forelse ($warnings as $warning)
                        <div class="incident incident-warning"><x-icon name="warning" size="20" /><div>
                            <p class="incident-title mono">{{ $warning['code'] }}</p>
                            <p>@isset($warning['aodb_id'])<span class="mono">{{ $warning['aodb_id'] }}</span>@endisset @isset($warning['detail'])<span class="mono">{{ $warning['detail'] }}</span>@endisset</p>
                        </div></div>
                    @empty
                        <div class="incident"><x-icon name="info" size="20" /><div>
                            <p class="incident-title">No warnings</p>
                            <p>The last poll recorded no warnings.</p>
                        </div></div>
                    @endforelse
                    @if ($pauses === [])
                        <div class="incident"><x-icon name="info" size="20" /><div>
                            <p class="incident-title">No AODB pauses</p>
                            <p>No login back-off or rate-limit pause is active.</p>
                        </div></div>
                    @endif
                </div>
            </section>
        </div>
    </div>

    <section class="card table-card">
        <div class="card-head padded">
            <h2><x-icon name="server" size="20" /> Recent polls</h2>
            <div class="chips" role="group" aria-label="Show polls">
                @foreach (['all' => 'All results', 'ok' => 'Successful', 'failed' => 'Failed', 'paused' => 'Paused'] as $value => $label)
                    <button type="button" class="filter-chip" wire:click="$set('pollFilter', '{{ $value }}')" aria-pressed="{{ $pollFilter === $value ? 'true' : 'false' }}">{{ $label }}</button>
                @endforeach
            </div>
            <span class="muted small">Last 10</span>
        </div>
        @if ($recent->isEmpty())
            <x-empty icon="server" title="No polls for this filter" hint="Polls run on the scheduler; try another filter." />
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th scope="col">Started (Kigali time)</th><th scope="col">Result</th><th scope="col">Outcome</th><th scope="col">HTTP status</th><th scope="col">Source</th><th scope="col" class="num">Duration ms</th><th scope="col">Details</th></tr></thead>
                    <tbody>
                    @foreach ($recent as $poll)
                        @php([$tone, $icon, $word] = Display::pollChip($poll->outcome))
                        <tr wire:key="poll-{{ $poll->id }}" @class(['row-error' => $tone === 'error'])>
                            <td><x-time :at="$poll->started_at" format="Y-m-d H:i:s" /></td>
                            <td><x-chip :tone="$tone" :icon="$icon">{{ $word }}</x-chip></td>
                            <td class="mono">{{ $poll->outcome }}</td>
                            <td class="mono">{{ $poll->http_status ?? '—' }}</td>
                            <td><span class="code code-src">AODB</span></td>
                            <td class="num">{{ $poll->duration_ms }}</td>
                            <td>
                                @if ($poll->outcome === 'ok')
                                    {{ $poll->flights_in_payload ?? 0 }} flights in the response · {{ $poll->flights_changed }} changed · {{ $poll->events_written }} changes processed
                                @else
                                    <span class="mono">{{ $poll->error_summary ?? $poll->outcome }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <p class="footnote">Refreshes every 60 seconds · rendered <x-time :at="$now" format="H:i:s" /> Kigali time</p>
</div>
