<x-layouts.app title="Dashboard">
    @if (session('status'))
        <div class="banner banner-ok" role="status"><x-icon name="check-circle" size="20" /><p>{{ session('status') }}</p></div>
    @endif
    <livewire:feed-health />
</x-layouts.app>
