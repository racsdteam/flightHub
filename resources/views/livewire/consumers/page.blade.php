@php($now = \Carbon\CarbonImmutable::now('UTC'))
<div>
    <span class="eyebrow"><a href="{{ route('consumers.list') }}">API consumers</a> › Consumer</span>
    <div class="page-head">
        <div class="title-row">
            <h1>API consumer <span class="code">{{ $consumer->code }}</span></h1>
            @if ($consumer->active)<x-chip tone="ok" icon="check-circle">Active</x-chip>@else<x-chip tone="disabled" icon="minus">Disabled</x-chip>@endif
        </div>
        <div class="head-actions">
            <a class="link-arrow" href="{{ route('consumers.usage', $consumer->code) }}"><x-icon name="chart" /> Usage</a>
            <a class="link-arrow" href="{{ route('api.docs') }}"><x-icon name="book" /> API documentation</a>
            <span class="code">Last saved <x-time :at="$consumer->updated_at" format="Y-m-d H:i:s" /></span>
        </div>
    </div>
    @if (session('status'))<div class="banner banner-ok" role="status"><x-icon name="check-circle" size="20" /><p>{{ session('status') }}</p></div>@endif

    <div class="grid grid-main-side">
        <form wire:submit="saveSettings" novalidate>
            <section class="card">
                <div class="card-head"><h2>Settings — {{ $consumer->name }}</h2></div>
                <div class="form-grid">
                    <div class="field"><label for="name">Consumer name</label><input id="name" wire:model="name">@error('name')<p class="field-error" role="alert">{{ $message }}</p>@enderror</div>
                    <div class="field"><label for="rate">Rate limit</label><input id="rate" type="number" min="1" max="10000" wire:model="rate" aria-describedby="rate-hint">
                        <p class="field-hint" id="rate-hint">Requests per minute, per key</p>@error('rate')<p class="field-error" role="alert">{{ $message }}</p>@enderror</div>
                    <div class="field"><span class="label">Status</span>
                        <p>@if ($consumer->active)<x-chip tone="ok" icon="check-circle">Active</x-chip>@else<x-chip tone="disabled" icon="minus">Disabled</x-chip>@endif</p>
                        <p class="field-hint">Enable or disable it in the <a href="{{ route('consumers.list') }}">consumer list</a>.</p></div>
                </div>
                <div class="field"><label for="notes">Notes</label><textarea id="notes" wire:model="notes" maxlength="500"></textarea>@error('notes')<p class="field-error" role="alert">{{ $message }}</p>@enderror</div>
            </section>

            <section class="card">
                <div class="card-head"><h2><x-icon name="shield" size="20" /> Allowed field groups</h2></div>
                <p class="card-sub">Ticked groups are included in this consumer's API responses.</p>
                <table class="kv groups-table">
                    <thead><tr><th scope="col"><span class="visually-hidden">Allowed</span></th><th scope="col">Field group</th><th scope="col">Fields</th></tr></thead>
                    <tbody>
                        <tr>
                            <td><input type="checkbox" id="group-identity" checked disabled></td>
                            <td><label for="group-identity" class="check"><span>identity</span></label><span class="sub muted small">always given</span></td>
                            <td><span class="fields">{{ implode(', ', $identity) }}</span></td>
                        </tr>
                        @foreach ($groupFields as $group => $fields)
                            <tr wire:key="group-{{ $group }}">
                                <td><input type="checkbox" id="group-{{ $group }}" value="{{ $group }}" wire:model="groups"></td>
                                <td><label for="group-{{ $group }}" class="check">{{ str_replace('_', ' ', $group) }}</label></td>
                                <td><span class="fields">{{ implode(', ', $fields) }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @error('groups.*')<p class="field-error" role="alert">{{ $message }}</p>@enderror
            </section>
            <div class="form-actions">
                <button type="submit" class="button"><x-icon name="check" /> Save settings</button>
                <a class="button button-secondary" href="{{ route('consumers.list') }}">Cancel</a>
            </div>
        </form>

        <div class="stack">
            <section class="card">
                <div class="card-head"><h2><x-icon name="chart" size="20" /> Usage summary</h2></div>
                <div class="usage-row"><x-icon name="arrow-right" size="20" /><div class="usage-main"><p class="usage-label">Requests today</p><p class="small muted">Kigali day</p></div><span class="usage-value">{{ $usage['today'] }}</span></div>
                <div class="usage-row"><x-icon name="calendar" size="20" /><div class="usage-main"><p class="usage-label">7-day average</p><p class="small muted">{{ $usage['week'] }} requests in the last 7 days</p></div><span class="usage-value">{{ round($usage['week'] / 7, 1) }}</span></div>
                <div class="usage-row"><x-icon name="pulse" size="20" /><div class="usage-main"><p class="usage-label">Last request</p>
                    @if ($usage['last'])<p class="small muted"><x-time :at="$usage['last']->at" format="Y-m-d H:i:s" /> · <span class="mono">{{ $usage['last']->path }}</span></p>@else<p class="small muted">No request yet</p>@endif</div>
                    @if ($usage['last'])
                        @php($code = (int) $usage['last']->status)
                        <x-chip :tone="$code < 400 ? 'ok' : ($code === 429 ? 'warning' : 'error')" :icon="$code < 400 ? 'check' : 'x'">HTTP {{ $code }}</x-chip>
                    @endif
                </div>
                <p class="card-foot"><a class="link-arrow" href="{{ route('consumers.usage', $consumer->code) }}">All calls <x-icon name="arrow-right" /></a></p>
            </section>
            <div class="banner banner-info"><x-icon name="info" size="20" /><p>Field groups and the rate limit apply from the next call. Existing keys stay valid.</p></div>
        </div>
    </div>

    <section class="card">
        <div class="card-head"><h2><x-icon name="key" size="20" /> API keys</h2></div>
        <p class="card-sub">One consumer can hold several keys. Only each key's hash is stored.</p>

        @if ($issuedKey)
            <div class="once-panel" role="alert">
                <x-icon name="warning" size="28" />
                <div class="once-body">
                    <p class="once-title">API key {{ $issuedPrefix }} issued — shown once</p>
                    <p>Copy it now: it is shown only this once and stored only as its hash. <strong class="text-stale">It will not be shown again.</strong></p>
                    <div class="once-row">
                        <span class="secret-value"><code id="issued-key">{{ $issuedKey }}</code></span>
                        <button type="button" class="button button-secondary" data-copy="issued-key"><x-icon name="copy" /> Copy key</button>
                        <button type="button" class="button" wire:click="$refresh"><x-icon name="check" /> Done</button>
                    </div>
                </div>
            </div>
        @endif

        <form wire:submit="issueKey" class="filters" novalidate>
            <div class="field"><label for="label">Label</label><input id="label" wire:model="label" placeholder="production">@error('label')<p class="field-error" role="alert">{{ $message }}</p>@enderror</div>
            <div class="field"><label for="expires">Expires <span class="muted">(optional, Kigali date)</span></label><input id="expires" type="date" wire:model="expires">@error('expires')<p class="field-error" role="alert">{{ $message }}</p>@enderror</div>
            <button type="submit" class="button"><x-icon name="plus" /> Issue new key</button>
        </form>

        @if ($keys->isEmpty())
            <x-empty icon="key" title="No keys yet" hint="Issue a key with a label; it is shown once." />
        @else
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th scope="col">Key prefix</th><th scope="col">Label</th><th scope="col">Status</th><th scope="col">Created</th><th scope="col">Last used</th><th scope="col">Expires</th><th scope="col">Revoked</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody>
                @foreach ($keys as $k)
                    @php($expired = $k->expires_at && \Carbon\CarbonImmutable::parse($k->expires_at, 'UTC')->lte($now))
                    <tr wire:key="key-{{ $k->id }}" @class(['row-muted' => $k->revoked_at || $expired])>
                        <td class="mono">{{ $k->prefix }}</td><td>{{ $k->label }}</td>
                        <td>@if ($k->revoked_at)<x-chip tone="disabled" icon="x">Revoked</x-chip>@elseif ($expired)<x-chip tone="warning" icon="clock">Expired</x-chip>@else<x-chip tone="ok" icon="check">Active</x-chip>@endif</td>
                        <td><x-time :at="$k->created_at" /> <span class="sub">{{ $k->created_by }}</span></td>
                        <td><x-time :at="$k->last_used_at" /></td>
                        <td>@if ($k->expires_at)<x-time :at="$k->expires_at" />@else<span class="muted">Never</span>@endif</td>
                        <td>@if ($k->revoked_at)<x-time :at="$k->revoked_at" /> <span class="sub">{{ $k->revoked_by }}</span>@else<span class="muted">—</span>@endif</td>
                        <td>@unless ($k->revoked_at)<button type="button" class="button button-danger button-xs" wire:click="revoke({{ $k->id }})">Revoke</button>@endunless</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </section>
</div>
