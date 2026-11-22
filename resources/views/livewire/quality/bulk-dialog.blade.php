{{-- Bulk resolution confirmation (D-057), quality list: the count, the rule, the filters and the
     resolution; a note (required for ignored and reported). --}}
<div class="dialog-backdrop">
    <div class="dialog" role="alertdialog" aria-modal="true" aria-labelledby="bulk-title" aria-describedby="bulk-summary" data-dialog>
        <div class="dialog-head">
            <h2 id="bulk-title"><x-icon name="layers" size="24" /> Resolve {{ count($bulkIds) }} {{ \Illuminate\Support\Str::plural('flag', count($bulkIds)) }} as “{{ $bulkResolution }}”</h2>
            <button type="button" class="button button-quiet" wire:click="cancelBulk" data-dialog-cancel aria-label="Close"><x-icon name="x" size="20" /></button>
        </div>
        <dl class="dl" id="bulk-summary">
            <dt>Flags</dt><dd class="mono">{{ count($bulkIds) }} (every unresolved flag matching the filters, all pages)</dd>
            <dt>Rule</dt><dd class="mono">{{ $rule }}</dd>
            <dt>Day (Kigali)</dt><dd>@if ($day !== '')<span class="mono">{{ $day }}</span>@else all days @endif</dd>
            <dt>State</dt><dd>{{ $state === '' ? 'open and cleared' : $state }}</dd>
            @if (trim($flight) !== '')<dt>Flight</dt><dd class="mono">{{ $flight }}</dd>@endif
            <dt>Resolution</dt><dd><x-chip tone="info" icon="check">{{ $bulkResolution }}</x-chip></dd>
        </dl>
        <div class="field">
            <label for="bulk-note">Note for every flag @if (in_array($bulkResolution, ['ignored', 'reported'], true))<span class="muted">(required)</span>@else<span class="muted">(optional)</span>@endif</label>
            <textarea id="bulk-note" wire:model="bulkNote" maxlength="500" placeholder="Why, or where it was reported"></textarea>
            @error('bulkNote') <p class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>
        <div class="banner banner-info"><x-icon name="info" size="24" /><p>Each flag keeps its own history and audit line, as when resolved one by one. A flag resolved by someone else in the meantime is skipped and counted.</p></div>
        <div class="actions actions-end">
            <button type="button" class="button button-secondary" wire:click="cancelBulk" data-autofocus>Cancel</button>
            <button type="button" class="button" wire:click="confirmBulk">Resolve {{ count($bulkIds) }} as “{{ $bulkResolution }}”</button>
        </div>
    </div>
</div>
