<x-layouts.app title="Feed health">
    @if (session('status'))
        <p class="notice" role="status">{{ session('status') }}</p>
    @endif
    <livewire:feed-health />
</x-layouts.app>
