<div>
    <div class="page-head"><h1>Audit log</h1><span class="muted">Append-only, kept forever · {{ $rows->total() }} entries</span></div>

    <div class="filters card">
        <div><label for="actor">User</label><select id="actor" wire:model.live="actor"><option value="">Any</option>@foreach ($actors as $a)<option value="{{ $a }}">{{ $a }}</option>@endforeach</select></div>
        <div><label for="action">Action</label><select id="action" wire:model.live="action"><option value="">Any</option>@foreach ($actions as $a)<option value="{{ $a }}">{{ $a }}</option>@endforeach</select></div>
        <div><label for="from">From (Kigali)</label><input id="from" type="date" wire:model.live="from"></div>
        <div><label for="to">To (Kigali)</label><input id="to" type="date" wire:model.live="to"></div>
    </div>

    <div class="card table-wrap">
        <table class="table">
            <thead><tr><th>At</th><th>Actor</th><th>Action</th><th>Target</th><th>Details</th><th>IP</th></tr></thead>
            <tbody>
            @forelse ($rows as $r)
                <tr wire:key="audit-{{ $r->id }}">
                    <td><x-time :at="$r->at" format="Y-m-d H:i:s" /></td>
                    <td class="mono">{{ $r->actor }}</td>
                    <td class="mono">{{ $r->action }}</td>
                    <td class="mono small">{{ $r->target_type }}{{ $r->target_id ? ' '.$r->target_id : '' }}</td>
                    <td class="mono small">{{ \Illuminate\Support\Str::limit((string) $r->details, 160) }}</td>
                    <td class="mono small">{{ $r->ip }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">No entries for these filters.</td></tr>
            @endforelse
            </tbody>
        </table>
        {{ $rows->links() }}
    </div>
</div>
