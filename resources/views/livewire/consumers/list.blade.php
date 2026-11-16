<div>
    <div class="page-head"><h1>Consumers</h1>
        <div class="head-links"><a href="{{ route('api.docs') }}">API documentation</a>
        @unless ($creating)<button type="button" class="button button-small" wire:click="startCreate">New consumer</button>@endunless</div></div>

    @if ($creating)
        <form wire:submit="create" class="card section" novalidate>
            <h2>New consumer</h2>
            <div class="form-grid">
                <div><label for="code">Code <span class="muted">(a-z, 0-9, -; e.g. kia-pulse)</span></label><input id="code" wire:model="code">@error('code')<p class="error">{{ $message }}</p>@enderror</div>
                <div><label for="name">Name</label><input id="name" wire:model="name">@error('name')<p class="error">{{ $message }}</p>@enderror</div>
            </div>
            <div class="actions"><button type="submit" class="button button-small">Create</button>
                <button type="button" class="button button-small button-secondary" wire:click="cancelCreate">Cancel</button></div>
        </form>
    @endif

    <div class="card table-wrap">
        <table class="table">
            <thead><tr><th>Code</th><th>Name</th><th>Groups</th><th>Rate / min</th><th>Live keys</th><th>Last used</th><th>State</th><th></th></tr></thead>
            <tbody>
            @forelse ($consumers as $c)
                <tr wire:key="consumer-{{ $c->id }}" @class(['row-muted' => ! $c->active])>
                    <td><a href="{{ route('consumers.show', $c->code) }}" class="mono">{{ $c->code }}</a></td>
                    <td>{{ $c->name }}</td>
                    <td class="small mono">{{ implode(', ', ['identity', ...(json_decode($c->field_groups, true) ?: [])]) }}</td>
                    <td class="num">{{ $c->rate_limit_per_minute }}</td>
                    <td class="num">{{ $c->live_keys }}</td>
                    <td><x-time :at="$c->last_used_at" /></td>
                    <td>@if ($c->active)<span class="badge badge-good">active</span>@else<span class="badge">disabled</span>@endif</td>
                    <td class="actions">
                        <a href="{{ route('consumers.usage', $c->code) }}">Usage</a>
                        <button type="button" class="button button-small button-secondary" wire:click="toggle({{ $c->id }})">{{ $c->active ? 'Disable' : 'Enable' }}</button>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="muted">No consumers yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
