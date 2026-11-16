<div>
    <div class="page-head">
        <div><h1>Users</h1><p class="page-sub">Admins and data stewards. Users are deactivated, never deleted.</p></div>
        @unless ($creating)<button type="button" class="button" wire:click="startCreate"><x-icon name="plus" /> New user</button>@endunless
    </div>
    @if (session('status'))<div class="banner banner-ok" role="status"><x-icon name="check-circle" size="20" /><p>{{ session('status') }}</p></div>@endif
    @if (session('error'))<div class="banner banner-error" role="alert"><x-icon name="x-circle" size="20" /><p>{{ session('error') }}</p></div>@endif

    @if ($temporaryPassword)
        <div class="once-panel" role="alert">
            <x-icon name="lock" size="28" />
            <div class="once-body">
                <p class="once-title">Temporary password — shown once</p>
                <p><strong>Temporary password for {{ $temporaryFor }}: shown only this once.</strong> Give it to them in person or by phone; they change it at first sign-in, after enrolling their authenticator.</p>
                <div class="once-row">
                    <span class="secret-value"><code id="temporary-password">{{ $temporaryPassword }}</code></span>
                    <button type="button" class="button button-secondary" data-copy="temporary-password"><x-icon name="copy" /> Copy</button>
                </div>
            </div>
        </div>
    @endif

    @if ($creating)
        <form wire:submit="create" class="card" novalidate>
            <div class="card-head"><h2>New user</h2></div>
            <div class="form-grid">
                <div class="field"><label for="email">Email</label><input id="email" type="email" wire:model="email">@error('email')<p class="field-error" role="alert">{{ $message }}</p>@enderror</div>
                <div class="field"><label for="name">Name</label><input id="name" wire:model="name">@error('name')<p class="field-error" role="alert">{{ $message }}</p>@enderror</div>
                <div class="field"><label for="role">Role</label><select id="role" wire:model="role"><option value="steward">Data steward</option><option value="admin">Admin</option></select></div>
            </div>
            <div class="actions"><button type="submit" class="button">Create</button>
                <button type="button" class="button button-secondary" wire:click="cancelCreate">Cancel</button></div>
        </form>
    @endif

    <div class="card table-card">
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th scope="col">Email</th><th scope="col">Name</th><th scope="col">Role</th><th scope="col">Authenticator</th><th scope="col">Last sign-in</th><th scope="col">State</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody>
                @foreach ($users as $u)
                    <tr wire:key="user-{{ $u->id }}" @class(['row-muted' => ! $u->active])>
                        <td class="mono">{{ $u->email }}@if ($u->id === auth()->id()) <x-chip tone="info" icon="user">you</x-chip>@endif</td>
                        <td>{{ $u->name }}</td>
                        <td>{{ $u->role === 'admin' ? 'Admin' : 'Data steward' }}</td>
                        <td>@if ($u->hasConfirmedTotp())<x-chip tone="ok" icon="check">Enrolled</x-chip>@else<x-chip tone="warning" icon="warning">Not enrolled</x-chip>@endif</td>
                        <td><x-time :at="$u->last_login_at" /></td>
                        <td>@if (! $u->active)<x-chip tone="disabled" icon="minus">Inactive</x-chip>@elseif ($u->isLocked())<x-chip tone="error" icon="lock">Locked</x-chip>@else<x-chip tone="ok" icon="check-circle">Active</x-chip>@endif</td>
                        <td><div class="actions">
                            @if ($u->role === 'admin')
                                <button type="button" class="button button-xs button-secondary" wire:click="changeRole({{ $u->id }}, 'steward')">Make steward</button>
                            @else
                                <button type="button" class="button button-xs button-secondary" wire:click="changeRole({{ $u->id }}, 'admin')">Make admin</button>
                            @endif
                            <button type="button" class="button button-xs button-secondary" wire:click="resetAccount({{ $u->id }})">Reset</button>
                            @if ($u->active)
                                <button type="button" class="button button-xs button-danger" wire:click="deactivate({{ $u->id }})">Deactivate</button>
                            @else
                                <button type="button" class="button button-xs" wire:click="reactivate({{ $u->id }})">Reactivate</button>
                            @endif
                        </div></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
