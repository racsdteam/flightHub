<div>
    <div class="page-head">
        <div>
            <h1>API consumers</h1>
            <p class="page-sub">Systems that read the hub through its API, each with its own keys and field groups.</p>
        </div>
        <div class="head-actions"><a class="link-arrow" href="{{ route('api.docs') }}"><x-icon name="book" /> API documentation</a>
        @unless ($creating)<button type="button" class="button" wire:click="startCreate"><x-icon name="plus" /> New consumer</button>@endunless</div>
    </div>

    @if ($creating)
        <form wire:submit="create" class="card" novalidate>
            <div class="card-head"><h2>New consumer</h2></div>
            <div class="form-grid">
                <div class="field"><label for="code">Code <span class="muted">(a-z, 0-9, -; e.g. kia-pulse)</span></label><input id="code" class="mono" wire:model="code">@error('code')<p class="field-error" role="alert">{{ $message }}</p>@enderror</div>
                <div class="field"><label for="name">Name</label><input id="name" wire:model="name">@error('name')<p class="field-error" role="alert">{{ $message }}</p>@enderror</div>
            </div>
            <div class="actions"><button type="submit" class="button">Create</button>
                <button type="button" class="button button-secondary" wire:click="cancelCreate">Cancel</button></div>
        </form>
    @endif

    <div class="card table-card">
        @if ($consumers->isEmpty())
            <x-empty icon="code" title="No consumers yet" hint="Create a consumer, then issue it a key." />
        @else
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th scope="col">Code</th><th scope="col">Name</th><th scope="col">Field groups</th><th scope="col" class="num">Rate / min</th><th scope="col" class="num">Live keys</th><th scope="col">Last used</th><th scope="col">State</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody>
                @foreach ($consumers as $c)
                    <tr wire:key="consumer-{{ $c->id }}" @class(['row-muted' => ! $c->active])>
                        <td><a href="{{ route('consumers.show', $c->code) }}" class="mono">{{ $c->code }}</a></td>
                        <td>{{ $c->name }}</td>
                        <td class="value-long">{{ implode(', ', ['identity', ...(json_decode($c->field_groups, true) ?: [])]) }}</td>
                        <td class="num">{{ $c->rate_limit_per_minute }}</td>
                        <td class="num">{{ $c->live_keys }}</td>
                        <td><x-time :at="$c->last_used_at" /></td>
                        <td>@if ($c->active)<x-chip tone="ok" icon="check-circle">Active</x-chip>@else<x-chip tone="disabled" icon="minus">Disabled</x-chip>@endif</td>
                        <td><div class="actions">
                            <a class="button button-quiet button-xs" href="{{ route('consumers.usage', $c->code) }}"><x-icon name="chart" size="14" /> Usage</a>
                            <button type="button" @class(['button', 'button-xs', 'button-secondary' => $c->active]) wire:click="toggle({{ $c->id }})">{{ $c->active ? 'Disable' : 'Enable' }}</button>
                        </div></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>
