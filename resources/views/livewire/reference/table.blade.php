<div>
    <div class="page-head"><h1>Reference data</h1><span class="muted">Rows are reviewed by the person who saves them.</span></div>
    <nav class="subnav" aria-label="Reference tables">
        <a href="{{ route('reference.airport-codes') }}" @class(['active' => request()->routeIs('reference.airport-codes')])>Airport code corrections</a>
        <a href="{{ route('reference.aircraft-types') }}" @class(['active' => request()->routeIs('reference.aircraft-types')])>Aircraft types</a>
        <a href="{{ route('reference.stands') }}" @class(['active' => request()->routeIs('reference.stands')])>Stands</a>
    </nav>
    @if (session('status'))<p class="notice" role="status">{{ session('status') }}</p>@endif
    @if ($pendingSince)<p class="small muted">Re-apply pending since <x-time :at="$pendingSince" format="H:i" />: served values update within 5 minutes.</p>@endif

    <section class="card section">
        <div class="page-head"><h2>{{ $this->title() }}</h2>
            @if ($editing === null)<button type="button" class="button button-small" wire:click="create">New row</button>@endif</div>

        @if ($editing !== null)
            <form wire:submit="save" class="card section" novalidate>
                <h3>{{ $editing === 0 ? 'New row' : 'Edit row' }}</h3>
                <div class="form-grid">
                    @foreach ($columns as $name => $col)
                        <div>
                            <label for="f-{{ $name }}">{{ $col['label'] }}@isset($col['hint']) <span class="muted">({{ $col['hint'] }})</span>@endisset</label>
                            @if (($col['type'] ?? 'text') === 'select')
                                <select id="f-{{ $name }}" wire:model="form.{{ $name }}"><option value="">—</option>@foreach ($col['options'] as $o)<option value="{{ $o }}">{{ $o }}</option>@endforeach</select>
                            @else
                                <input id="f-{{ $name }}" type="{{ ($col['type'] ?? 'text') === 'number' ? 'number' : 'text' }}" wire:model="form.{{ $name }}">
                            @endif
                            @error('form.'.$name) <p class="error" role="alert">{{ $message }}</p> @enderror
                        </div>
                    @endforeach
                </div>
                <div class="actions">
                    <button type="submit" class="button button-small">Save</button>
                    <button type="button" class="button button-small button-secondary" wire:click="cancel">Cancel</button>
                </div>
            </form>
        @endif

        <div class="table-wrap">
            <table class="table">
                <thead><tr>@foreach ($columns as $col)<th>{{ $col['label'] }}</th>@endforeach<th>Reviewed</th><th>State</th><th></th></tr></thead>
                <tbody>
                @forelse ($rows as $row)
                    <tr wire:key="ref-{{ $row->id }}" @class(['row-muted' => ! $row->active])>
                        @foreach ($columns as $name => $col)<td class="mono">{{ $row->{$name} ?? '—' }}</td>@endforeach
                        <td class="small">{{ $row->reviewed_by }} · {{ $row->reviewed_on }}</td>
                        <td>@if ($row->active)<span class="badge badge-good">active</span>@else<span class="badge">inactive</span>@endif</td>
                        <td class="actions">
                            <button type="button" class="button button-small button-secondary" wire:click="edit({{ $row->id }})">Edit</button>
                            <button type="button" class="button button-small button-secondary" wire:click="toggle({{ $row->id }})">{{ $row->active ? 'Deactivate' : 'Reactivate' }}</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="{{ count($columns) + 3 }}" class="muted">No rows yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
