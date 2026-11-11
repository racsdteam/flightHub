<div wire:poll.60s class="dashboard">
    <h1>Feed health</h1>

    <div class="grid">
        <section @class(['card', 'tile', 'tile-good' => $feed['feed'] === 'ok', 'tile-critical' => $feed['feed'] === 'stale'])>
            <h2>Feed</h2>
            <p class="figure">{{ $feed['feed'] === 'ok' ? 'OK' : 'STALE' }}</p>
            <p class="muted">Stale after 15 minutes without a good poll.</p>
        </section>

        <section class="card tile">
            <h2>Last good poll</h2>
            @if ($lastOk)
                <p class="figure"><x-time :at="$lastOk->finished_at" format="H:i:s" /></p>
                <p class="muted">{{ \App\Admin\Times::age($lastOk->finished_at) }} · <x-time :at="$lastOk->finished_at" format="Y-m-d" /></p>
            @else
                <p class="figure">—</p><p class="muted">No good poll yet.</p>
            @endif
        </section>

        <section @class(['card', 'tile', 'tile-warning' => $last && $last->outcome !== 'ok'])>
            <h2>Last poll</h2>
            @if ($last)
                <p class="figure mono">{{ $last->outcome }}</p>
                <p class="muted">HTTP {{ $last->http_status ?? '—' }} · <x-time :at="$last->finished_at" format="H:i:s" />@if ($last->error_summary) · <span class="mono">{{ $last->error_summary }}</span>@endif</p>
            @else
                <p class="figure">—</p>
            @endif
        </section>

        <section @class(['card', 'tile', 'tile-warning' => $pauses !== []])>
            <h2>AODB pauses</h2>
            @forelse ($pauses as $label => $until)
                <p>{{ $label }} until <x-time :at="$until" format="H:i" /></p>
            @empty
                <p class="figure">None</p>
            @endforelse
        </section>
    </div>

    <div class="grid">
        <section class="card">
            <h2>Flights today <span class="muted">(Kigali <x-time :at="$day->from" format="Y-m-d" />)</span></h2>
            <p class="figure">{{ $total }}</p>
            <table class="kv">
                @foreach ($byFlow as $flow => $n)<tr><th>{{ $flow }}</th><td class="mono">{{ $n }}</td></tr>@endforeach
            </table>
            <h3>By status</h3>
            <table class="kv">
                @forelse ($byStatus as $status => $n)<tr><th class="mono">{{ $status }}</th><td class="mono">{{ $n }}</td></tr>@empty<tr><td class="muted">None</td></tr>@endforelse
            </table>
        </section>

        <section class="card">
            <h2>Open quality flags</h2>
            <p class="figure">{{ array_sum($flags) }}</p>
            <table class="kv">
                @forelse ($flags as $rule => $n)<tr><th class="mono">{{ $rule }}</th><td class="mono">{{ $n }}</td></tr>@empty<tr><td class="muted">None</td></tr>@endforelse
            </table>
        </section>

        <section class="card">
            <h2>Polls, last 24 h</h2>
            <p class="figure">{{ array_sum($polls24h) }}</p>
            <table class="kv">
                @forelse ($polls24h as $outcome => $n)<tr><th class="mono">{{ $outcome }}</th><td class="mono">{{ $n }}</td></tr>@empty<tr><td class="muted">None</td></tr>@endforelse
            </table>
        </section>

        <section class="card">
            <h2>Last poll's warnings</h2>
            @forelse ($warnings as $w)
                <p class="small"><span class="mono">{{ $w['code'] }}</span>@isset($w['detail']) · <span class="mono">{{ $w['detail'] }}</span>@endisset</p>
            @empty
                <p class="muted">None</p>
            @endforelse
        </section>
    </div>

    <p class="muted small">Refreshes every 60 seconds · rendered <x-time :at="$now" format="H:i:s" /> Kigali time</p>
</div>
