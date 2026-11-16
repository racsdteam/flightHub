{{-- Empty state: an icon, a message and an optional hint (no action buttons). --}}
@props(['icon' => 'plane', 'title', 'hint' => null])
<div {{ $attributes->merge(['class' => 'empty']) }}>
    <x-icon :name="$icon" size="32" />
    <p class="empty-title">{{ $title }}</p>
    @if ($hint)<p class="empty-hint">{{ $hint }}</p>@endif
    {{ $slot }}
</div>
