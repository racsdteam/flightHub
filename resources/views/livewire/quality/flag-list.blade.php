@use('App\Admin\Display')
<div>
    <div class="page-head">
        <div>
            <span class="eyebrow">Data quality</span>
            <h1>Data quality</h1>
            <p class="page-sub">Quality flags raised by the hub's rules · {{ $flags->total() }} for these filters</p>
        </div>
    </div>

    @if (session('status'))<div class="banner banner-ok" role="status"><x-icon name="check-circle" size="20" /><p>{{ session('status') }}</p></div>@endif

    <div class="rule-tiles">
        <div class="card rule-tile summary">
            <x-icon name="shield" size="20" />
            <span class="tile-value">{{ $openNow }}</span>
            <span class="rule-label">Open flags</span>
            @if ($openTrend > 0)<x-chip tone="error" icon="arrow-up">+{{ $openTrend }} vs 24 h ago</x-chip>
            @elseif ($openTrend < 0)<x-chip tone="ok" icon="arrow-down">{{ $openTrend }} vs 24 h ago</x-chip>
            @else<x-chip tone="disabled" icon="minus">0 vs 24 h ago</x-chip>@endif
        </div>
        <div class="card rule-tile summary">
            <x-icon name="check-circle" size="20" />
            <span class="tile-value">{{ $clearedDay }}</span>
            <span class="rule-label">Cleared, last 24 h</span>
            @php($d = $clearedDay - $clearedPrev)
            <x-chip :tone="$d === 0 ? 'disabled' : 'neutral'" :icon="$d > 0 ? 'arrow-up' : ($d < 0 ? 'arrow-down' : 'minus')">{{ $d > 0 ? '+' : '' }}{{ $d }} vs 24 h before</x-chip>
        </div>
        @foreach ($counts as $r => $n)
            <button type="button" class="card rule-tile" wire:click="$set('rule', '{{ $rule === $r ? '' : $r }}')" aria-pressed="{{ $rule === $r ? 'true' : 'false' }}">
                <x-icon :name="Display::RULE_ICONS[$r] ?? 'shield'" size="20" />
                <span class="tile-value">{{ $n }}</span>
                <span class="rule-name">{!! str_replace('_', '_<wbr>', e($r)) !!}</span>
            </button>
        @endforeach
    </div>

    <div class="card filters filters-labelled" role="search" aria-label="Filter quality flags">
        <div class="field"><label for="rule">Rule</label>
            <select id="rule" wire:model.live="rule"><option value="">All rules</option>@foreach ($rules as $r)<option value="{{ $r }}">{{ $r }}</option>@endforeach</select></div>
        <div class="field"><label for="state">State</label>
            <select id="state" wire:model.live="state"><option value="">All (open and cleared)</option><option value="open">Open</option><option value="cleared">Cleared</option></select></div>
        <div class="field"><label for="resolution">Resolution</label>
            <select id="resolution" wire:model.live="resolution"><option value="">All resolutions</option><option value="none">No resolution</option>@foreach ($resolutions as $r)<option value="{{ $r }}">{{ str_replace('_', ' ', $r) }}</option>@endforeach</select></div>
        <div class="field"><label for="day">Day (Kigali)</label>
            <div class="input-icon"><x-icon name="calendar" /><input id="day" type="date" wire:model.live="day"></div></div>
        <div class="field"><label for="flight">Flight</label>
            <div class="input-icon"><x-icon name="search" /><input id="flight" type="search" wire:model.live.debounce.400ms="flight" placeholder="Flight number or AODB id"></div></div>
    </div>

    @if ($canAct && $rule !== '' && $bulkCount > 0)
        {{-- Bulk resolution (D-057): every unresolved flag matching the filters, all pages. --}}
        <div class="card bulk-bar" role="group" aria-label="Resolve every flag shown">
            @if ($bulkCount > $bulkLimit)
                <p><x-icon name="info" size="18" /> <strong>Resolve all {{ $bulkCount }} shown:</strong> more than {{ $bulkLimit }}. Narrow the filters (a day, a state or a flight) to resolve at most {{ $bulkLimit }} at a time.</p>
            @else
                <p><strong>Resolve all {{ $bulkCount }} shown</strong> <span class="muted">{{ $rule }}, every page, no resolution yet</span></p>
                <div class="actions">
                    <button type="button" class="button button-xs button-secondary" wire:click="askBulk('fixed')"><x-icon name="check" size="14" /> Fixed</button>
                    <button type="button" class="button button-xs button-secondary" wire:click="askBulk('ignored')"><x-icon name="minus" size="14" /> Ignored</button>
                    <button type="button" class="button button-xs button-secondary" wire:click="askBulk('reported')"><x-icon name="arrow-up" size="14" /> Reported</button>
                </div>
            @endif
            @error('bulk') <p class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>
    @endif

    <div class="card table-card">
        @if ($flags->isEmpty())
            <x-empty icon="check-circle" title="No flags for these filters" hint="Try another state, rule or day." />
        @else
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th scope="col">Rule</th><th scope="col">Flight</th><th scope="col">Field</th><th scope="col">State</th><th scope="col">Resolution</th><th scope="col">Detected</th><th scope="col">Actions</th></tr></thead>
                <tbody>
                @foreach ($flags as $f)
                    <tr wire:key="flag-{{ $f->id }}" @class(['row-error' => $f->state === 'open' && ! $f->resolution])>
                        <td><span class="cell-flight"><x-icon :name="Display::RULE_ICONS[$f->rule] ?? 'shield'" /><a href="{{ route('quality.flag', $f->id) }}" class="mono">{{ $f->rule }}</a></span></td>
                        <td class="nowrap"><a href="{{ route('flights.show', $f->aodb_id) }}" class="mono">{{ $f->full_flight_number }} {{ $f->flow }}</a><span class="sub"><x-time :at="$f->reference_time" format="m-d H:i" /></span></td>
                        <td>@if ($f->field){{ Display::fieldLabel($f->field) }}<span class="sub mono">{{ $f->field }}</span>@else<span class="muted">—</span>@endif</td>
                        <td>@if ($f->state === 'open')<x-chip tone="error" icon="alert">Open</x-chip>@else<x-chip tone="ok" icon="check-circle">Cleared</x-chip>@endif</td>
                        <td>@if ($f->resolution)<x-chip tone="info" icon="check">{{ str_replace('_', ' ', $f->resolution) }}</x-chip>@if ($f->resolution_note)<span class="sub">{{ \Illuminate\Support\Str::limit($f->resolution_note, 60) }}</span>@endif @else<span class="muted">—</span>@endif</td>
                        <td class="nowrap"><x-time :at="$f->first_detected_at" format="m-d H:i" /><span class="sub">last <x-time :at="$f->last_detected_at" format="m-d H:i" /></span></td>
                        <td>
                            <div class="actions row-actions">
                                @if ($canAct)
                                    <button type="button" class="button button-xs button-secondary" wire:click="resolve({{ $f->id }}, 'fixed')" @disabled($f->resolution === 'fixed')><x-icon name="check" size="14" /> Fixed</button>
                                    <button type="button" class="button button-xs button-secondary" wire:click="resolve({{ $f->id }}, 'ignored')" @disabled($f->resolution === 'ignored')><x-icon name="minus" size="14" /> Ignored</button>
                                    <button type="button" class="button button-xs button-secondary" wire:click="resolve({{ $f->id }}, 'reported')" @disabled($f->resolution === 'reported')><x-icon name="arrow-up" size="14" /> Reported</button>
                                    @if ($f->rule === 'duplicate_suspected' && $f->related_flight_id && $f->resolution !== 'same_as')
                                        <button type="button" class="button button-xs button-danger" wire:click="askSameAs({{ $f->id }})"><x-icon name="duplicate" size="14" /> Same as</button>
                                    @endif
                                    @if ($f->resolution)<button type="button" class="button button-xs button-quiet" wire:click="resolve({{ $f->id }}, 'none')">Remove resolution</button>@endif
                                @endif
                                <a class="button button-xs button-quiet" href="{{ route('quality.flag', $f->id) }}">Open <x-icon name="arrow-right" size="14" /></a>
                            </div>
                        </td>
                    </tr>
                    @if ($canAct && $noteFor === $f->id)
                        <tr class="note-row" wire:key="note-{{ $f->id }}">
                            <td colspan="7">
                                <div class="note-inline">
                                    <label for="note-{{ $f->id }}">Note for “{{ $noteResolution }}” <span class="muted">(required)</span></label>
                                    <input id="note-{{ $f->id }}" wire:model="note" maxlength="500" placeholder="Why, or where it was reported" autofocus>
                                    <button type="button" class="button button-xs" wire:click="resolve({{ $f->id }}, '{{ $noteResolution }}')">Save “{{ $noteResolution }}”</button>
                                    <button type="button" class="button button-xs button-secondary" wire:click="cancelNote">Cancel</button>
                                </div>
                                @error('note') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                            </td>
                        </tr>
                    @endif
                @endforeach
                </tbody>
            </table>
        </div>
        {{ $flags->links() }}
        @endif
    </div>

    @if ($canAct && $dialog)
        @include('livewire.quality.same-as-dialog', $dialog)
    @endif

    @if ($canAct && $bulkResolution)
        @include('livewire.quality.bulk-dialog')
    @endif
</div>
