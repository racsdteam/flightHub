<div>
    <span class="eyebrow"><a href="{{ route('consumers.list') }}">API consumers</a> › <a href="{{ route('consumers.show', $consumer->code) }}">{{ $consumer->code }}</a> › Usage</span>
    <div class="page-head">
        <div><h1>API usage <span class="mono">{{ $consumer->code }}</span></h1><p class="page-sub">Every call is logged (kept 1 year); keys and the Authorization header never are.</p></div>
        <a class="link-arrow" href="{{ route('consumers.show', $consumer->code) }}"><x-icon name="chevron-left" /> Consumer</a>
    </div>

    <div class="card filters" role="search" aria-label="Filter calls">
        <div class="field"><label for="days">Last days</label><input id="days" type="number" min="1" max="365" wire:model.live.debounce.400ms="days"></div>
        <div class="field"><label for="status">HTTP status</label><input id="status" class="mono" wire:model.live.debounce.400ms="status" placeholder="200"></div>
        <div class="field grow"><label for="path">Path contains</label><div class="input-icon"><x-icon name="search" /><input id="path" class="mono" wire:model.live.debounce.400ms="path" placeholder="/api/v1/changes"></div></div>
    </div>

    <div class="grid grid-2">
        <section class="card">
            <div class="card-head"><h2><x-icon name="server" size="20" /> Per HTTP status</h2></div>
            @if ($statuses === [])<x-empty icon="chart" title="No calls" />@else
            <table class="kv"><thead><tr><th scope="col">Status</th><th scope="col" class="num">Calls</th></tr></thead><tbody>
                @foreach ($statuses as $s => $n)<tr><td>@php($code = (int) $s)<x-chip :tone="$code < 400 ? 'ok' : ($code === 429 ? 'warning' : 'error')" :icon="$code < 400 ? 'check' : 'x'">{{ $s }}</x-chip></td><td class="num">{{ $n }}</td></tr>@endforeach
            </tbody></table>@endif
        </section>
        <section class="card">
            <div class="card-head"><h2><x-icon name="calendar" size="20" /> Per Kigali day</h2></div>
            @if ($perDay->isEmpty())<x-empty icon="chart" title="No calls" />@else
            <table class="kv"><thead><tr><th scope="col">Day</th><th scope="col">Calls by status</th></tr></thead><tbody>
                @foreach ($perDay as $day => $rows)<tr><td class="mono">{{ $day }}</td><td class="mono">@foreach ($rows as $r){{ $r->status }}: {{ $r->n }}@if (! $loop->last) · @endif @endforeach</td></tr>@endforeach
            </tbody></table>@endif
        </section>
    </div>

    <div class="card table-card">
        @if ($calls->isEmpty())
            <x-empty icon="chart" title="No calls for these filters" hint="Widen the number of days or clear the status and path filters." />
        @else
        <div class="table-wrap">
            <table class="table table-compact">
                <thead><tr><th scope="col">At (Kigali time)</th><th scope="col">Method</th><th scope="col">Path</th><th scope="col">Status</th><th scope="col" class="num">Rows</th><th scope="col" class="num">ms</th><th scope="col">Error</th><th scope="col">IP</th></tr></thead>
                <tbody>
                @foreach ($calls as $c)
                    <tr wire:key="call-{{ $c->id }}" @class(['row-error' => $c->status >= 400])><td><x-time :at="$c->at" format="Y-m-d H:i:s" /></td><td class="mono">{{ $c->method }}</td><td class="mono">{{ $c->path }}</td>
                        <td><span class="code">{{ $c->status }}</span></td><td class="num">{{ $c->rows ?? '—' }}</td><td class="num">{{ $c->duration_ms }}</td><td class="mono">{{ $c->error_code ?? '' }}</td><td class="mono">{{ $c->ip }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
        {{ $calls->links() }}
        @endif
    </div>
</div>
