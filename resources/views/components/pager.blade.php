{{-- Livewire pagination (previous / next), styled by hub.css. --}}
@if ($paginator->hasPages())
<nav class="pager" aria-label="Pages">
    @if ($paginator->onFirstPage())
        <span class="muted">Previous</span>
    @else
        <button type="button" class="link" wire:click="previousPage('{{ $paginator->getPageName() }}')">Previous</button>
    @endif
    <span class="muted">Page {{ $paginator->currentPage() }}@if (method_exists($paginator, 'lastPage')) of {{ $paginator->lastPage() }}@endif</span>
    @if ($paginator->hasMorePages())
        <button type="button" class="link" wire:click="nextPage('{{ $paginator->getPageName() }}')">Next</button>
    @else
        <span class="muted">Next</span>
    @endif
</nav>
@endif
