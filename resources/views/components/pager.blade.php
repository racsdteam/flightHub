{{-- Livewire pagination (WithPagination's own previousPage / nextPage / gotoPage), styled by hub.css. --}}
@if ($paginator->hasPages())
@php
    $page = $paginator->currentPage();
    $name = $paginator->getPageName();
    $last = method_exists($paginator, 'lastPage') ? $paginator->lastPage() : null;
    $pages = $last === null ? [] : collect([1, $page - 2, $page - 1, $page, $page + 1, $page + 2, $last])->filter(fn ($p) => $p >= 1 && $p <= $last)->unique()->sort()->values();
@endphp
<nav class="pager" aria-label="Pages">
    @if ($last !== null && method_exists($paginator, 'total'))
        <span class="pager-info">{{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} of {{ $paginator->total() }}</span>
    @endif
    <button type="button" class="edge" wire:click="previousPage('{{ $name }}')" @disabled($paginator->onFirstPage()) aria-label="Previous page"><x-icon name="chevron-left" /></button>
    @foreach ($pages as $i => $p)
        @if ($i > 0 && $p > $pages[$i - 1] + 1)<span class="gap" aria-hidden="true">…</span>@endif
        @if ($p === $page)
            <span class="page current" aria-current="page">{{ $p }}</span>
        @else
            <button type="button" wire:click="gotoPage({{ $p }}, '{{ $name }}')" aria-label="Page {{ $p }}">{{ $p }}</button>
        @endif
    @endforeach
    @if ($last === null)<span class="page current" aria-current="page">{{ $page }}</span>@endif
    <button type="button" class="edge" wire:click="nextPage('{{ $name }}')" @disabled(! $paginator->hasMorePages()) aria-label="Next page"><x-icon name="chevron-right" /></button>
</nav>
@endif
