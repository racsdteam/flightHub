{{-- AODB status in words, with the AODB milestone as a second label when it says more. --}}
@props(['status' => null, 'milestone' => null])
@php([$tone, $icon] = \App\Admin\Display::statusTone($status))
@php($word = \App\Admin\Display::status($status))
@php($second = \App\Admin\Display::milestone($milestone))
<span class="status-cell"><x-chip :tone="$tone" :icon="$icon">{{ $word }}</x-chip>@if ($second && $second !== $word)<span class="milestone">{{ $second }}</span>@endif</span>
