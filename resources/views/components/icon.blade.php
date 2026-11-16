{{--
    Hand-drawn line icons (24-unit grid, stroke = currentColor). Decorative: always next to a
    word, so hidden from assistive technology. Sizes are SVG attributes (CSP: no style).
--}}
@props(['name', 'size' => 16])
@php
$paths = [
    'home' => '<path d="M3 11l9-7 9 7"/><path d="M5 10v10h5v-6h4v6h5V10"/>',
    'pulse' => '<path d="M3 12h4l2-6 4 12 2-6h6"/>',
    'plane' => '<path d="M2 13l8-1 5-8h2l-2 8 5 1 2-2h1l-1 3 1 3h-1l-2-2-5 1 2 8h-2l-5-8-8-1z"/>',
    'shield' => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/>',
    'layers' => '<path d="M12 3l9 5-9 5-9-5z"/><path d="M3 13l9 5 9-5"/>',
    'code' => '<path d="M8 7l-5 5 5 5"/><path d="M16 7l5 5-5 5"/><path d="M14 4l-4 16"/>',
    'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.6 2.9-6 6.5-6s6.5 2.4 6.5 6"/><path d="M16 4.6a3.5 3.5 0 010 6.8"/><path d="M18 14.4c2.1.7 3.5 2.8 3.5 5.6"/>',
    'file' => '<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4"/><path d="M9 12h6M9 16h6"/>',
    'search' => '<circle cx="11" cy="11" r="6.5"/><path d="M16 16l5 5"/>',
    'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v6"/><path d="M12 7.5v.5"/>',
    'check' => '<path d="M4 12.5l5 5L20 6.5"/>',
    'check-circle' => '<circle cx="12" cy="12" r="9"/><path d="M8 12.5l3 3 5-6"/>',
    'x' => '<path d="M6 6l12 12M18 6L6 18"/>',
    'x-circle' => '<circle cx="12" cy="12" r="9"/><path d="M9 9l6 6M15 9l-6 6"/>',
    'warning' => '<path d="M12 3.5l9.5 16.5h-19z"/><path d="M12 10v4.5"/><path d="M12 17.5v.5"/>',
    'alert' => '<circle cx="12" cy="12" r="9"/><path d="M12 7.5v5.5"/><path d="M12 16.5v.5"/>',
    'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    'calendar' => '<rect x="3.5" y="5" width="17" height="15.5" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
    'copy' => '<rect x="8.5" y="8.5" width="12" height="12" rx="2"/><path d="M15.5 8.5V5a1.5 1.5 0 00-1.5-1.5H5A1.5 1.5 0 003.5 5v9A1.5 1.5 0 005 15.5h3.5"/>',
    'download' => '<path d="M12 4v11"/><path d="M7 10.5l5 5 5-5"/><path d="M4 20h16"/>',
    'key' => '<circle cx="8" cy="15" r="4.5"/><path d="M11.2 11.8L20 3"/><path d="M16.5 6.5l3 3M14 9l2 2"/>',
    'lock' => '<rect x="4.5" y="10.5" width="15" height="10" rx="2"/><path d="M8 10.5V7a4 4 0 018 0v3.5"/>',
    'chevron-down' => '<path d="M6 9l6 6 6-6"/>',
    'chevron-left' => '<path d="M15 6l-6 6 6 6"/>',
    'chevron-right' => '<path d="M9 6l6 6-6 6"/>',
    'arrow-up' => '<path d="M12 20V5"/><path d="M6 11l6-6 6 6"/>',
    'arrow-down' => '<path d="M12 4v15"/><path d="M6 13l6 6 6-6"/>',
    'arrow-right' => '<path d="M4 12h15"/><path d="M13 6l6 6-6 6"/>',
    'pause' => '<circle cx="12" cy="12" r="9"/><path d="M10 9v6M14 9v6"/>',
    'minus' => '<circle cx="12" cy="12" r="9"/><path d="M8 12h8"/>',
    'server' => '<rect x="3.5" y="4" width="17" height="7" rx="1.5"/><rect x="3.5" y="13" width="17" height="7" rx="1.5"/><path d="M7 7.5h.5M7 16.5h.5"/>',
    'chart' => '<path d="M5 20V12M10 20V6M15 20v-9M20 20V4"/>',
    'link' => '<path d="M10 14a4 4 0 005.7 0l3.5-3.5a4 4 0 00-5.7-5.7L12 6.3"/><path d="M14 10a4 4 0 00-5.7 0l-3.5 3.5a4 4 0 005.7 5.7l1.5-1.5"/>',
    'rotate' => '<path d="M20 12a8 8 0 01-14 5.3"/><path d="M4 12a8 8 0 0114-5.3"/><path d="M18 3v4h-4M6 21v-4h4"/>',
    'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-7 8-7s8 2.6 8 7"/>',
    'plus' => '<path d="M12 5v14M5 12h14"/>',
    'book' => '<path d="M4 4.5h6a2 2 0 012 2V20a2 2 0 00-2-2H4z"/><path d="M20 4.5h-6a2 2 0 00-2 2V20a2 2 0 012-2h6z"/>',
    'tag' => '<path d="M3.5 12.5V4h8.5l8.5 8.5-8.5 8.5z"/><path d="M8 8h.5"/>',
    'duplicate' => '<rect x="8.5" y="3.5" width="12" height="12" rx="2"/><rect x="3.5" y="8.5" width="12" height="12" rx="2"/>',
    'gauge' => '<path d="M4 18a8 8 0 1116 0"/><path d="M12 18l4-6"/>',
    'mark' => '<path d="M4 20L14 4h6L10 20z"/>',
];
@endphp
<svg {{ $attributes->merge(['class' => 'icon']) }} width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">{!! $paths[$name] ?? $paths['info'] !!}</svg>
