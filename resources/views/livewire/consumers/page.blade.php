<div>
    <div class="page-head">
        <h1 class="mono">{{ $consumer->code }}</h1>
        <span>@if ($consumer->active)<span class="badge badge-good">active</span>@else<span class="badge">disabled</span>@endif
            <a href="{{ route('consumers.usage', $consumer->code) }}">Usage</a> · <a href="{{ route('consumers.list') }}">All consumers</a></span>
    </div>
    @if (session('status'))<p class="notice" role="status">{{ session('status') }}</p>@endif

    @if ($issuedKey)
        <div class="secret-box" role="alert">
            <p><strong>New key {{ $issuedPrefix }}: copy it now. It is shown only this once and stored only as its hash.</strong></p>
            <p><code id="issued-key">{{ $issuedKey }}</code></p>
            <button type="button" class="button button-small" data-copy="issued-key">Copy</button>
        </div>
    @endif

    <form wire:submit="saveSettings" class="card section" novalidate>
        <h2>Settings</h2>
        <div class="form-grid">
            <div><label for="name">Name</label><input id="name" wire:model="name">@error('name')<p class="error">{{ $message }}</p>@enderror</div>
            <div><label for="rate">Rate limit (requests per minute per key)</label><input id="rate" type="number" wire:model="rate">@error('rate')<p class="error">{{ $message }}</p>@enderror</div>
        </div>
        <label>Field groups <span class="muted">(identity is always given: {{ implode(', ', $identity) }})</span></label>
        <div class="groups">
            @foreach ($groupFields as $group => $fields)
                <div>
                    <label class="check"><input type="checkbox" value="{{ $group }}" wire:model="groups"> {{ str_replace('_', ' ', $group) }}</label>
                    <p class="fields">{{ implode(', ', $fields) }}</p>
                </div>
            @endforeach
        </div>
        @error('groups.*')<p class="error">{{ $message }}</p>@enderror
        <label for="notes">Notes</label><textarea id="notes" wire:model="notes" maxlength="500"></textarea>
        <div class="actions"><button type="submit" class="button button-small">Save settings</button></div>
    </form>

    <section class="card section">
        <h2>Keys</h2>
        <form wire:submit="issueKey" class="form-grid" novalidate>
            <div><label for="label">Label</label><input id="label" wire:model="label" placeholder="production">@error('label')<p class="error">{{ $message }}</p>@enderror</div>
            <div><label for="expires">Expires (optional, Kigali date)</label><input id="expires" type="date" wire:model="expires">@error('expires')<p class="error">{{ $message }}</p>@enderror</div>
            <div class="actions"><button type="submit" class="button button-small">Issue key</button></div>
        </form>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Prefix</th><th>Label</th><th>Created</th><th>Last used</th><th>Expires</th><th>Revoked</th><th></th></tr></thead>
                <tbody>
                @forelse ($keys as $k)
                    <tr wire:key="key-{{ $k->id }}" @class(['row-muted' => $k->revoked_at])>
                        <td class="mono">{{ $k->prefix }}</td><td>{{ $k->label }}</td>
                        <td><x-time :at="$k->created_at" /> <span class="muted small">{{ $k->created_by }}</span></td>
                        <td><x-time :at="$k->last_used_at" /></td>
                        <td><x-time :at="$k->expires_at" /></td>
                        <td>@if ($k->revoked_at)<x-time :at="$k->revoked_at" /> <span class="muted small">{{ $k->revoked_by }}</span>@else — @endif</td>
                        <td>@unless ($k->revoked_at)<button type="button" class="button button-small button-danger" wire:click="revoke({{ $k->id }})">Revoke</button>@endunless</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="muted">No keys yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
