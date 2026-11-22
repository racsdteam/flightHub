<div>
    <div class="page-head">
        <div><h1>Reference data</h1><p class="page-sub">Rows are reviewed by the person who saves them; served values follow within 5 minutes.</p></div>
    </div>
    <nav class="subnav" aria-label="Reference tables">
        <a href="{{ route('reference.airport-codes') }}" @class(['active' => request()->routeIs('reference.airport-codes')]) @if (request()->routeIs('reference.airport-codes')) aria-current="page" @endif>Airport code corrections</a>
        <a href="{{ route('reference.aircraft-types') }}" @class(['active' => request()->routeIs('reference.aircraft-types')]) @if (request()->routeIs('reference.aircraft-types')) aria-current="page" @endif>Aircraft types</a>
        <a href="{{ route('reference.stands') }}" @class(['active' => request()->routeIs('reference.stands')]) @if (request()->routeIs('reference.stands')) aria-current="page" @endif>Stands</a>
    </nav>
    @if (session('status'))<div class="banner banner-ok" role="status"><x-icon name="check-circle" size="20" /><p>{{ session('status') }}</p></div>@endif
    @if ($pendingSince)
        {{-- Since when: the time today (Kigali), the date and time when it dates from an earlier day. --}}
        @php($sinceFormat = \App\Admin\Times::kigali($pendingSince, 'Y-m-d') === \Carbon\CarbonImmutable::now('Africa/Kigali')->format('Y-m-d') ? 'H:i' : 'Y-m-d H:i')
        <div class="banner banner-warning" role="status"><x-icon name="clock" size="20" /><p>Re-apply pending since <x-time :at="$pendingSince" :format="$sinceFormat" />: served values update within 5 minutes.</p></div>
    @endif

    <section class="card table-card">
        <div class="card-head padded"><h2><x-icon name="layers" size="20" /> {{ $this->title() }}</h2>
            @if ($editing === null)<button type="button" class="button button-small" wire:click="create"><x-icon name="plus" size="14" /> New row</button>@endif</div>

        @if ($editing !== null)
            <form wire:submit="save" class="card inset" novalidate>
                <div class="card-head"><h3>{{ $editing === 0 ? 'New row' : 'Edit row' }}</h3></div>
                <div class="form-grid">
                    @foreach ($columns as $name => $col)
                        <div class="field">
                            <label for="f-{{ $name }}">{{ $col['label'] }}@isset($col['hint']) <span class="muted">({{ $col['hint'] }})</span>@endisset</label>
                            @if (($col['type'] ?? 'text') === 'select')
                                <select id="f-{{ $name }}" wire:model="form.{{ $name }}"><option value="">—</option>@foreach ($col['options'] as $o)<option value="{{ $o }}">{{ $o }}</option>@endforeach</select>
                            @else
                                <input id="f-{{ $name }}" type="{{ ($col['type'] ?? 'text') === 'number' ? 'number' : 'text' }}" wire:model="form.{{ $name }}">
                            @endif
                            @error('form.'.$name) <p class="field-error" role="alert">{{ $message }}</p> @enderror
                        </div>
                    @endforeach
                </div>
                <div class="actions">
                    <button type="submit" class="button button-small">Save</button>
                    <button type="button" class="button button-small button-secondary" wire:click="cancel">Cancel</button>
                </div>
            </form>
        @endif

        @if ($rows->isEmpty())
            <x-empty icon="layers" title="No rows yet" hint="Add a reviewed row with “New row”." />
        @else
        <div class="table-wrap">
            <table class="table">
                <thead><tr>@foreach ($columns as $col)<th scope="col">{{ $col['label'] }}</th>@endforeach<th scope="col">Reviewed</th><th scope="col">State</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody>
                @foreach ($rows as $row)
                    <tr wire:key="ref-{{ $row->id }}" @class(['row-muted' => ! $row->active])>
                        @foreach ($columns as $name => $col)<td class="mono">{{ $row->{$name} ?? '—' }}</td>@endforeach
                        <td class="small">{{ $row->reviewed_by }}<span class="sub mono">{{ $row->reviewed_on }}</span></td>
                        <td>@if ($row->active)<x-chip tone="ok" icon="check-circle">Active</x-chip>@else<x-chip tone="disabled" icon="minus">Inactive</x-chip>@endif</td>
                        <td><div class="actions">
                            <button type="button" class="button button-xs button-secondary" wire:click="edit({{ $row->id }})">Edit</button>
                            <button type="button" class="button button-xs button-secondary" wire:click="toggle({{ $row->id }})">{{ $row->active ? 'Deactivate' : 'Reactivate' }}</button>
                        </div></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </section>
</div>
