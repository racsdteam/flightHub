@use('App\Admin\Display')
<div>
    <span class="eyebrow"><a href="{{ route('quality.flags') }}">Data quality</a> › Flag</span>
    <div class="page-head">
        <div class="flight-head">
            <h1><x-icon :name="Display::RULE_ICONS[$flag->rule] ?? 'shield'" size="26" /> {{ $flag->rule }}@if ($flag->field) · {{ $flag->field }}@endif</h1>
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
        <div class="dialog-backdrop">
            <div class="dialog" role="alertdialog" aria-modal="true" aria-labelledby="same-as-title" aria-describedby="same-as-warning" data-dialog>
                <div class="dialog-head">
                    <h2 id="same-as-title"><x-icon name="duplicate" size="24" /> Mark as “Same as” (suspected duplicate)</h2>
                    <button type="button" class="button button-quiet" wire:click="cancelSameAs" data-dialog-cancel aria-label="Close"><x-icon name="x" size="20" /></button>
                </div>
                <div class="banner banner-stale" id="same-as-warning"><x-icon name="warning" size="24" /><p><span class="banner-title-sm">You are about to mark one flight as the same as another (duplicate).</span>
                    The flight to hide leaves flight lists and the API; the flight to keep stays as the single record.</p></div>

                @foreach ([['hide', $flight, 'FLIGHT TO HIDE', 'x-circle', 'Duplicate — will be hidden from flight lists and the API'], ['keep', $related, 'FLIGHT TO KEEP', 'check-circle', 'Kept — stays visible and carries the history']] as [$kind, $f, $tag, $icon, $text])
                    @if ($kind === 'keep')
                        <div class="compare-link" aria-hidden="true"><x-icon name="arrow-down" size="16" /><span class="chip chip-info">Same as</span></div>
                    @endif
                    <div class="compare compare-{{ $kind }}">
                        <div class="compare-side">
                            <span class="compare-tag">{{ $tag }}</span>
                            <p><x-icon :name="$icon" size="18" /> {{ $text }}</p>
                        </div>
                        <div class="compare-fields">
                            <div><p class="label">Flight</p><p class="value mono">{{ $f->full_flight_number ?? '—' }}</p></div>
                            <div><p class="label">Direction</p><p class="value"><x-icon :name="$f->flow === 'ARR' ? 'arrow-down' : 'arrow-up'" size="14" /> {{ Display::flow($f->flow) }}</p></div>
                            <div><p class="label">Other airport</p><p class="value mono">{{ ($f->flow === 'ARR' ? $f->adep_iata : $f->ades_iata) ?? '—' }}</p></div>
                            <div><p class="label">Scheduled</p><p class="value"><x-time :at="$f->flow === 'ARR' ? $f->sibt : $f->sobt" format="Y-m-d H:i" /></p></div>
                            <div><p class="label">Registration</p><p class="value mono">{{ $f->reg ?? '—' }}</p></div>
                            <div><p class="label">Status</p><p class="value"><x-flight-status :status="$f->status" /></p></div>
                            <div><p class="label">Open flags</p><p class="value"><span @class(['code', 'count-chip' => ($openCounts[$f->id] ?? 0) > 0])>{{ $openCounts[$f->id] ?? 0 }}</span></p></div>
                            <p class="compare-id">AODB id <span class="mono">{{ $f->aodb_id }}</span></p>
                        </div>
                    </div>
                @endforeach

                <div class="field unserved">
                    <label for="same-as-note">Note for the record <span class="muted">(optional)</span></label>
                    <textarea id="same-as-note" wire:model="note" maxlength="500" placeholder="Why these flights are the same (e.g. the AODB sent the flight twice)"></textarea>
                </div>
                <div class="banner banner-info"><x-icon name="info" size="24" /><p><span class="banner-title-sm">Confirm: mark as same as?</span>
                    The hidden flight stays reachable by its id and in the change history, with hub.sameAs pointing to the kept one (D-038).</p></div>
                <div class="actions actions-end">
                    <button type="button" class="button button-secondary" wire:click="cancelSameAs" data-autofocus>Cancel</button>
                    <button type="button" class="button" wire:click="confirmSameAs">Confirm “Mark as same as”</button>
                </div>
            </div>
        </div>
    @endif
</div>
