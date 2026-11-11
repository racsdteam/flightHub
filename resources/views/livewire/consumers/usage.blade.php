<div>
    <div class="page-head"><h1>API usage · <span class="mono">{{ $consumer->code }}</span></h1>
        <a href="{{ route('consumers.show', $consumer->code) }}">Consumer</a></div>

    <div class="filters card">
        <div><label for="days">Last days</label><input id="days" type="number" min="1" max="365" wire:model.live.debounce.400ms="days"></div>
        <div><label for="status">Status</label><input id="status" wire:model.live.debounce.400ms="status" placeholder="200"></div>
        <div><label for="path">Path contains</label><input id="path" wire:model.live.debounce.400ms="path" placeholder="/api/v1/changes"></div>
    </div>

    <div class="two-col">
        <section class="card section">
            <h2>Per status</h2>
            <table class="kv">@forelse ($statuses as $s => $n)<tr><th class="mono">{{ $s }}</th><td class="mono">{{ $n }}</td></tr>@empty<tr><td class="muted">No calls</td></tr>@endforelse</table>
        </section>
        <section class="card section">
            <h2>Per Kigali day</h2>
            <table class="kv">@forelse ($perDay as $day => $rows)<tr><th class="mono">{{ $day }}</th><td class="mono">@foreach ($rows as $r){{ $r->status }}: {{ $r->n }}@if (! $loop->last) · @endif @endforeach</td></tr>@empty<tr><td class="muted">No calls</td></tr>@endforelse</table>
        </section>
    </div>

    <div class="card table-wrap">
        <table class="table">
            <thead><tr><th>At</th><th>Method</th><th>Path</th><th>Status</th><th>Rows</th><th>ms</th><th>Error</th><th>IP</th></tr></thead>
            <tbody>
            @forelse ($calls as $c)
                <tr wire:key="call-{{ $c->id }}"><td><x-time :at="$c->at" format="Y-m-d H:i:s" /></td><td class="mono">{{ $c->method }}</td><td class="mono">{{ $c->path }}</td>
                    <td class="num">{{ $c->status }}</td><td class="num">{{ $c->rows ?? '—' }}</td><td class="num">{{ $c->duration_ms }}</td><td class="mono">{{ $c->error_code ?? '' }}</td><td class="mono small">{{ $c->ip }}</td></tr>
            @empty
                <tr><td colspan="8" class="muted">No calls for these filters.</td></tr>
            @endforelse
            </tbody>
        </table>
        {{ $calls->links() }}
    </div>
</div>
