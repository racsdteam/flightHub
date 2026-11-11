@props(['at', 'format' => 'Y-m-d H:i'])
@php($utc = \App\Admin\Times::utc($at))
@if ($utc)
<time class="mono" datetime="{{ $utc->format('Y-m-d\TH:i:s\Z') }}" title="UTC {{ $utc->format('Y-m-d H:i:s') }}">{{ \App\Admin\Times::kigali($utc, $format) }}</time>
@else
<span class="muted">—</span>
@endif
