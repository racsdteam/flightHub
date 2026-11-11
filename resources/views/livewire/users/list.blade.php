<div>
    <div class="page-head"><h1>Users</h1>
        @unless ($creating)<button type="button" class="button button-small" wire:click="startCreate">New user</button>@endunless</div>
    @if (session('status'))<p class="notice" role="status">{{ session('status') }}</p>@endif
    @if (session('error'))<p class="error" role="alert">{{ session('error') }}</p>@endif

    @if ($temporaryPassword)
        <div class="secret-box" role="alert">
            <p><strong>Temporary password for {{ $temporaryFor }}: shown only this once.</strong> Give it to them in person or by phone; they change it at first sign-in, after enrolling their authenticator.</p>
            <p><code id="temporary-password">{{ $temporaryPassword }}</code></p>
            <button type="button" class="button button-small" data-copy="temporary-password">Copy</button>
        </div>
    @endif

    @if ($creating)
        <form wire:submit="create" class="card section" novalidate>
            <h2>New user</h2>
            <div class="form-grid">
                <div><label for="email">Email</label><input id="email" type="email" wire:model="email">@error('email')<p class="error">{{ $message }}</p>@enderror</div>
                <div><label for="name">Name</label><input id="name" wire:model="name">@error('name')<p class="error">{{ $message }}</p>@enderror</div>
                <div><label for="role">Role</label><select id="role" wire:model="role"><option value="steward">Data steward</option><option value="admin">Admin</option></select></div>
            </div>
            <div class="actions"><button type="submit" class="button button-small">Create</button>
                <button type="button" class="button button-small button-secondary" wire:click="cancelCreate">Cancel</button></div>
        </form>
    @endif

    <div class="card table-wrap">
        <table class="table">
            <thead><tr><th>Email</th><th>Name</th><th>Role</th><th>Authenticator</th><th>Last sign-in</th><th>State</th><th></th></tr></thead>
            <tbody>
            @foreach ($users as $u)
                <tr wire:key="user-{{ $u->id }}" @class(['row-muted' => ! $u->active])>
                    <td class="mono">{{ $u->email }}@if ($u->id === auth()->id()) <span class="badge badge-accent">you</span>@endif</td>
                    <td>{{ $u->name }}</td>
                    <td>{{ $u->role === 'admin' ? 'Admin' : 'Data steward' }}</td>
                    <td>@if ($u->hasConfirmedTotp())<span class="badge badge-good">enrolled</span>@else<span class="badge badge-warning">not enrolled</span>@endif</td>
                    <td><x-time :at="$u->last_login_at" /></td>
                    <td>@if (! $u->active)<span class="badge">inactive</span>@elseif ($u->isLocked())<span class="badge badge-critical">locked</span>@else<span class="badge badge-good">active</span>@endif</td>
                    <td class="actions">
                        @if ($u->role === 'admin')
                            <button type="button" class="button button-small button-secondary" wire:click="changeRole({{ $u->id }}, 'steward')">Make steward</button>
                        @else
                            <button type="button" class="button button-small button-secondary" wire:click="changeRole({{ $u->id }}, 'admin')">Make admin</button>
                        @endif
                        <button type="button" class="button button-small button-secondary" wire:click="resetAccount({{ $u->id }})">Reset</button>
                        @if ($u->active)
                            <button type="button" class="button button-small button-danger" wire:click="deactivate({{ $u->id }})">Deactivate</button>
                        @else
                            <button type="button" class="button button-small" wire:click="reactivate({{ $u->id }})">Reactivate</button>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
