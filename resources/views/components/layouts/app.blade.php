<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? 'Feed health' }} · RAC Flight Data Hub</title>
    <link rel="stylesheet" href="{{ asset('css/hub.css') }}">
    @auth
        @livewireStyles
    @endauth
</head>
<body>
@if (auth()->check() && ($feed['feed'] ?? null) === 'stale')
    <div class="banner banner-critical" role="alert">
        Feed stale: the last good AODB poll was
        @if ($feed['asOf']) <x-time :at="$feed['asOf']" /> Kigali time. @else never. @endif
        Data shown may be out of date.
    </div>
@endif

@auth
<header class="topbar">
    <div class="brand">RAC Flight Data Hub</div>
    <nav class="nav" aria-label="Main">
        {{-- No navigation until the account is ready (TOTP enrolled, password changed). --}}
        @if (auth()->user()->hasConfirmedTotp() && ! auth()->user()->must_change_password)
        @can('view-hub')
            <a href="{{ route('dashboard') }}" @class(['active' => request()->routeIs('dashboard')])>Feed health</a>
        @endcan
        @endif
    </nav>
    <div class="user">
        <span>{{ auth()->user()->name }}</span>
        <span class="pill">{{ auth()->user()->role === 'admin' ? 'Admin' : 'Data steward' }}</span>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="link">Sign out</button>
        </form>
    </div>
</header>
@endauth

<main class="@auth page @else page-narrow @endauth">
    {{ $slot }}
</main>

<footer class="footer">Times are Kigali time (UTC+2); hover a time for UTC.</footer>

@auth
    @livewireScripts
@endauth
</body>
</html>
