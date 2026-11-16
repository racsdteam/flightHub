@use('App\Admin\Display')
<div>
    <span class="eyebrow"><a href="{{ route('quality.flags') }}">Data quality</a> › Flag</span>
    <div class="page-head">
        <div class="flight-head">
            <h1><x-icon :name="Display::RULE_ICONS[$flag->rule] ?? 'shield'" size="26" /> {{ $flag->rule }}@if ($flag->field) · {{ Display::fieldLabel($flag->field) }}@endif</h1>
            @if ($flag->state === 'open')<x-chip tone="error" icon="alert">Open</x-chip>@else<x-chip tone="ok" icon="check-circle">Cleared</x-chip>@endif
            @if ($flag->resolution)<x-chip tone="info" icon="check">{{ str_replace('_', ' ', $flag->resolution) }}</x-chip>@endif
        </div>
        <a class="link-arrow" href="{{ route('quality.flags') }}"><x-icon name="chevron-left" /> All flags</a>
    </div>
    @if (session('status'))<div class="banner banner-ok" role="status"><x-icon name="check-circle" size="20" /><p>{{ session('status') }}</p></div>@endif

    <div class="grid grid-2">
        <section class="card">
            <div class="card-head"><h2><x-icon name="info" size="20" /> Details</h2></div>
            <dl class="dl">
                <dt>Flight</dt><dd><a href="{{ route('flights.show', $flight->aodb_id) }}" class="mono">{{ $flight->full_flight_number }} {{ $flight->flow }}</a> <span class="muted mono">{{ $flight->aodb_id }}</span></dd>
                @if ($related)<dt>Related flight</dt><dd><a href="{{ route('flights.show', $related->aodb_id) }}" class="mono">{{ $related->full_flight_number }} {{ $related->flow }}</a> <span class="muted mono">{{ $related->aodb_id }}</span></dd>@endif
                <dt>First detected</dt><dd><x-time :at="$flag->first_detected_at" /></dd>
                <dt>Last detected</dt><dd><x-time :at="$flag->last_detected_at" /></dd>
                <dt>Cleared</dt><dd><x-time :at="$flag->cleared_at" /></dd>
                @foreach ($details as $key => $value)<dt class="mono">{{ $key }}</dt><dd class="mono">{{ is_scalar($value) || $value === null ? ($value ?? '—') : json_encode($value) }}</dd>@endforeach
                <dt>Resolution</dt><dd>{{ $flag->resolution ? str_replace('_', ' ', $flag->resolution) : '—' }}@if ($flag->resolved_by) · {{ $flag->resolved_by }} · <x-time :at="$flag->resolved_at" />@endif</dd>
                @if ($flag->resolution_note)<dt>Note</dt><dd>{{ $flag->resolution_note }}</dd>@endif
            </dl>
        </section>

        <section class="card">
            <div class="card-head"><h2><x-icon name="check-circle" size="20" /> Resolve</h2></div>
            @if ($canAct)
                <div class="field">
                    <label for="note">Note <span class="muted">(required for ignored and reported)</span></label>
                    <textarea id="note" wire:model="note" maxlength="500"></textarea>
                    @error('note') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div class="actions">
                    <button type="button" class="button button-small" wire:click="resolve('fixed')"><x-icon name="check" size="14" /> Fixed</button>
                    <button type="button" class="button button-small button-secondary" wire:click="resolve('ignored')"><x-icon name="minus" size="14" /> Ignored</button>
                    <button type="button" class="button button-small button-secondary" wire:click="resolve('reported')"><x-icon name="arrow-up" size="14" /> Reported to Innov'ATM</button>
                    @if ($flag->resolution)<button type="button" class="button button-small button-quiet" wire:click="resolve('none')">Remove resolution</button>@endif
                    @if ($isDuplicate && $flag->resolution !== 'same_as')<button type="button" class="button button-small button-danger" wire:click="askSameAs"><x-icon name="duplicate" size="14" /> Same as…</button>@endif
                </div>
            @else
                <div class="banner banner-info"><x-icon name="lock" size="20" /><p>Resolving flags needs the data steward or admin role.</p></div>
            @endif
        </section>
    </div>

    <section class="card table-card">
        <div class="card-head padded"><h2><x-icon name="clock" size="20" /> Events</h2></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th scope="col">At (Kigali time)</th><th scope="col">Kind</th><th scope="col">From</th><th scope="col">To</th><th scope="col">By</th><th scope="col">Note</th></tr></thead>
                <tbody>
                @foreach ($events as $e)
                    <tr wire:key="fe-{{ $e->id }}"><td><x-time :at="$e->at" format="Y-m-d H:i:s" /></td><td class="mono">{{ $e->kind }}</td><td class="mono">{{ $e->from_value ?? '—' }}</td><td class="mono">{{ $e->to_value ?? '—' }}</td><td>{{ $e->actor ?? 'hub' }}</td><td>{{ $e->note }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>

    @if ($canAct && $confirmingSameAs)
        @include('livewire.quality.same-as-dialog', ['flight' => $flight, 'related' => $related, 'openCounts' => $openCounts])
    @endif
</div>
