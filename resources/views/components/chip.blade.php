{{-- A status chip: tone colour + icon + word (status is never shown by colour alone). --}}
@props(['tone' => 'neutral', 'icon' => null])
<span {{ $attributes->merge(['class' => 'chip chip-'.$tone]) }}>@if ($icon)<x-icon :name="$icon" size="14" />@endif{{ $slot }}</span>
