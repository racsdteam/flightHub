{{-- The "same as" confirmation (07), used by the flag page and the quality list:
     $flight = the flight to hide (the flag's), $related = the flight to keep, $openCounts. --}}
@use('App\Admin\Display')
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
            The hidden flight can still be opened by its id and keeps its change history; it points to the kept flight.</p></div>
        <div class="actions actions-end">
            <button type="button" class="button button-secondary" wire:click="cancelSameAs" data-autofocus>Cancel</button>
            <button type="button" class="button" wire:click="confirmSameAs">Confirm “Mark as same as”</button>
        </div>
    </div>
</div>
