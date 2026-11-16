{{-- One served value in words: times in Kigali (UTC in the title), Yes / No, — for none, no JSON quotes. --}}
@props(['value' => null])
@if (\App\Admin\Display::isUtcTime($value))
<x-time :at="$value" />
@elseif ($value === null || $value === '' || $value === [])
<span class="muted">—</span>
@else
<span {{ $attributes }}>{{ \App\Admin\Display::text($value) }}</span>
@endif
