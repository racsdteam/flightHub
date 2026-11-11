<div>
    <div class="page-head">
        <h1 class="mono">{{ $flag->rule }}@if ($flag->field) · {{ $flag->field }}@endif</h1>
        <span><span @class(['badge', 'badge-open' => $flag->state === 'open'])>{{ $flag->state }}</span>
        @if ($flag->resolution)<span class="badge badge-accent">{{ $flag->resolution }}</span>@endif
        <a href="{{ route('quality.flags') }}">All flags</a></span>
    </div>
    @if (session('status'))<p class="notice" role="status">{{ session('status') }}</p>@endif

    <div class="two-col">
        <section class="card section">
            <h2>Details</h2>
            <dl class="dl">
                <dt>flight</dt><dd><a href="{{ route('flights.show', $flight->aodb_id) }}">{{ $flight->full_flight_number }} {{ $flight->flow }}</a> <span class="muted">{{ $flight->aodb_id }}</span></dd>
                @if ($related)<dt>related flight</dt><dd><a href="{{ route('flights.show', $related->aodb_id) }}">{{ $related->full_flight_number }} {{ $related->flow }}</a> <span class="muted">{{ $related->aodb_id }}</span></dd>@endif
                <dt>first detected</dt><dd><x-time :at="$flag->first_detected_at" /></dd>
                <dt>last detected</dt><dd><x-time :at="$flag->last_detected_at" /></dd>
                <dt>cleared</dt><dd><x-time :at="$flag->cleared_at" /></dd>
                @foreach ($details as $key => $value)<dt>{{ $key }}</dt><dd>{{ is_scalar($value) || $value === null ? ($value ?? '—') : json_encode($value) }}</dd>@endforeach
                <dt>resolution</dt><dd>{{ $flag->resolution ?? '—' }}@if ($flag->resolved_by) · {{ $flag->resolved_by }} · <x-time :at="$flag->resolved_at" />@endif</dd>
                @if ($flag->resolution_note)<dt>note</dt><dd>{{ $flag->resolution_note }}</dd>@endif
            </dl>
        </section>

        <section class="card section">
            <h2>Resolve</h2>
            @if ($canAct)
                <label for="note">Note <span class="muted">(required for ignored and reported)</span></label>
                <textarea id="note" wire:model="note" maxlength="500"></textarea>
                @error('note') <p class="error" role="alert">{{ $message }}</p> @enderror
                <div class="actions">
                    <button type="button" class="button button-small" wire:click="resolve('fixed')">Fixed</button>
                    <button type="button" class="button button-small button-secondary" wire:click="resolve('ignored')">Ignored</button>
                    <button type="button" class="button button-small button-secondary" wire:click="resolve('reported')">Reported to Innov'ATM</button>
                    @if ($flag->resolution)<button type="button" class="button button-small button-secondary" wire:click="resolve('none')">Remove resolution</button>@endif
                    @if ($isDuplicate && $flag->resolution !== 'same_as')<button type="button" class="button button-small button-danger" wire:click="askSameAs">Same as…</button>@endif
                </div>
                @if ($confirmingSameAs)
                    <div class="confirm" role="alertdialog">
                        <p><strong>HIDDEN</strong> from flight lists: <span class="mono">{{ $flight->full_flight_number }} {{ $flight->flow }} · {{ $flight->aodb_id }}</span></p>
                        <p><strong>KEPT</strong>: <span class="mono">{{ $related->full_flight_number }} {{ $related->flow }} · {{ $related->aodb_id }}</span></p>
                        <p class="muted small">The hidden flight stays reachable by its id and in the change history, with hub.sameAs pointing to the kept one (D-038).</p>
                        <div class="actions">
                            <button type="button" class="button button-small button-danger" wire:click="confirmSameAs">Confirm: same as</button>
                            <button type="button" class="button button-small button-secondary" wire:click="cancelSameAs">Cancel</button>
                        </div>
                    </div>
                @endif
            @else
                <p class="muted">Resolving flags needs the data steward or admin role.</p>
            @endif
        </section>
    </div>

    <section class="card section">
        <h2>Events</h2>
        <table class="table">
            <thead><tr><th>At</th><th>Kind</th><th>From</th><th>To</th><th>By</th><th>Note</th></tr></thead>
            <tbody>
            @foreach ($events as $e)
                <tr wire:key="fe-{{ $e->id }}"><td><x-time :at="$e->at" format="Y-m-d H:i:s" /></td><td class="mono">{{ $e->kind }}</td><td class="mono">{{ $e->from_value ?? '—' }}</td><td class="mono">{{ $e->to_value ?? '—' }}</td><td>{{ $e->actor ?? 'hub' }}</td><td>{{ $e->note }}</td></tr>
            @endforeach
            </tbody>
        </table>
    </section>
</div>
