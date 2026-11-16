<div>
    <div class="page-head">
        <div><h1>Audit log</h1><p class="page-sub">Append-only, kept forever · {{ $rows->total() }} entries</p></div>
    </div>

    <div class="card filters" role="search" aria-label="Filter the audit log">
        <div class="field"><label for="actor">User</label><select id="actor" wire:model.live="actor"><option value="">All users</option>@foreach ($actors as $a)<option value="{{ $a }}">{{ $a }}</option>@endforeach</select></div>
        <div class="field"><label for="action">Action</label><select id="action" wire:model.live="action"><option value="">All actions</option>@foreach ($actions as $a)<option value="{{ $a }}">{{ $a }}</option>@endforeach</select></div>
        <div class="field"><label for="from">From (Kigali)</label><div class="input-icon"><x-icon name="calendar" /><input id="from" type="date" wire:model.live="from"></div></div>
        <div class="field"><label for="to">To (Kigali)</label><div class="input-icon"><x-icon name="calendar" /><input id="to" type="date" wire:model.live="to"></div></div>
    </div>

    <div class="card table-card">
        @if ($rows->isEmpty())
            <x-empty icon="file" title="No entries for these filters" hint="Try another user, action or date range." />
        @else
        <div class="table-wrap">
            <table class="table table-compact">
                <thead><tr><th scope="col">At (Kigali time)</th><th scope="col">Actor</th><th scope="col">Action</th><th scope="col">Target</th><th scope="col">Details</th><th scope="col">IP</th></tr></thead>
                <tbody>
                @foreach ($rows as $r)
                    <tr wire:key="audit-{{ $r->id }}" @class(['row-error' => str_contains($r->action, 'fail') || str_contains($r->action, 'lock')])>
                        <td><x-time :at="$r->at" format="Y-m-d H:i:s" /></td>
                        <td class="mono">{{ $r->actor }}</td>
                        <td><span class="code">{{ $r->action }}</span></td>
                        <td class="mono">{{ $r->target_type }}{{ $r->target_id ? ' '.$r->target_id : '' }}</td>
                        <td class="value-long">{{ \Illuminate\Support\Str::limit((string) $r->details, 160) }}</td>
                        <td class="mono">{{ $r->ip }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        {{ $rows->links() }}
        @endif
    </div>
</div>
