{{--
    The admin layout (D-052). Signed in with the account ready (authenticator enrolled, password
    changed): the shell — navy top bar, left navigation limited to the user's abilities. Otherwise
    (sign-in, two-factor, enrolment, forced password change): a standalone page without navigation.
--}}
@php
    $user = auth()->user();
    $ready = $user && $user->hasConfirmedTotp() && ! $user->must_change_password;
    $now = \Carbon\CarbonImmutable::now('UTC');
    $stale = $user && ($feed['feed'] ?? null) === 'stale' && ! request()->routeIs('dashboard');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? 'Dashboard' }} · RAC Flight Data Hub</title>
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/favicon-32.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('images/apple-touch-icon-180.png') }}">
    <link rel="stylesheet" href="{{ asset('css/hub.css') }}">
    @auth
        @livewireStyles
    @endauth
</head>
<body @class(['standalone' => ! $ready])>
<a class="skip-link" href="#main">Skip to content</a>

<header class="topbar">
    <a class="brand" href="{{ $ready ? route('dashboard') : ($user ? '#main' : route('login')) }}">
        <span class="brand-tile"><img src="{{ asset('images/rac-logo-80.png') }}" width="40" height="40" alt="Rwanda Airports Company"></span>
        <span>Flight Data Hub</span>
    </a>

    @if ($ready)
        @can('view-hub')
            <form class="top-search" role="search" method="GET" action="{{ route('flights.day') }}">
                <label class="visually-hidden" for="top-search">Search today's flights</label>
                <x-icon name="search" />
                <input id="top-search" name="q" type="search" placeholder="Search flight number or registration" autocomplete="off">
            </form>
        @endcan
        <span class="time-chip" title="Now {{ \App\Admin\Times::kigali($now, 'H:i') }} Kigali · UTC {{ $now->format('H:i') }}" tabindex="0">
            Kigali time (UTC+2) <x-icon name="info" size="14" />
        </span>
    @endif

    @if ($user)
        <details class="user-menu">
            <summary>
                <span class="user-who"><span class="user-name">{{ $user->name }}</span><span class="user-role">{{ $user->role === 'admin' ? 'Admin' : 'Data steward' }}</span></span>
                <span class="avatar"><x-icon name="user" size="18" /></span>
                <x-icon name="chevron-down" size="14" />
            </summary>
            <div class="menu">
                <p class="menu-who"><span>{{ $user->name }}</span><span class="muted">{{ $user->role === 'admin' ? 'Admin' : 'Data steward' }}</span></p>
                @if ($ready)<a class="menu-item" href="{{ route('password.change') }}">Change password</a>@endif
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="menu-item">Sign out</button>
                </form>
            </div>
        </details>
    @endif
</header>

@if ($ready)
<div class="frame">
    <nav class="sidenav" aria-label="Main">
        @can('view-hub')
            <a href="{{ route('dashboard') }}" @class(['active' => request()->routeIs('dashboard')]) @if (request()->routeIs('dashboard')) aria-current="page" @endif><x-icon name="home" size="20" /> Dashboard</a>
            <a href="{{ route('flights.day') }}" @class(['active' => request()->routeIs('flights.*')]) @if (request()->routeIs('flights.*')) aria-current="page" @endif><x-icon name="plane" size="20" /> Flights</a>
            <a href="{{ route('quality.flags') }}" @class(['active' => request()->routeIs('quality.*')]) @if (request()->routeIs('quality.*')) aria-current="page" @endif><x-icon name="shield" size="20" /> Data quality</a>
        @endcan
        @can('manage-reference')
            <a href="{{ route('reference.airport-codes') }}" @class(['active' => request()->routeIs('reference.*')]) @if (request()->routeIs('reference.*')) aria-current="page" @endif><x-icon name="layers" size="20" /> Reference data</a>
        @endcan
        @can('manage-consumers')
            <a href="{{ route('consumers.list') }}" @class(['active' => request()->routeIs('consumers.*')]) @if (request()->routeIs('consumers.*')) aria-current="page" @endif><x-icon name="code" size="20" /> API consumers</a>
        @endcan
        @can('manage-users')
            <a href="{{ route('users.list') }}" @class(['active' => request()->routeIs('users.*')]) @if (request()->routeIs('users.*')) aria-current="page" @endif><x-icon name="users" size="20" /> Users</a>
        @endcan
        @can('view-audit')
            <a href="{{ route('audit.log') }}" @class(['active' => request()->routeIs('audit.*')]) @if (request()->routeIs('audit.*')) aria-current="page" @endif><x-icon name="file" size="20" /> Audit log</a>
        @endcan
    </nav>

    <main id="main" class="content">
        @if ($stale)
            <div class="banner banner-stale" role="alert">
                <x-icon name="warning" size="20" />
                <p><strong>Feed stale:</strong> the last good AODB poll was
                @if ($feed['asOf']) <x-time :at="$feed['asOf']" /> Kigali time. @else never. @endif
                Data shown may be out of date.</p>
            </div>
        @endif
        {{ $slot }}
        <p class="footnote">Times are Kigali time (UTC+2); hover a time for UTC.</p>
    </main>
</div>
@else
<main id="main" class="standalone-main">
    <img class="auth-logo" src="{{ asset('images/rac-logo-240.png') }}" width="120" height="120" alt="">
    @if ($stale)
        <div class="banner banner-stale" role="alert">
            <x-icon name="warning" size="20" />
            <p><strong>Feed stale:</strong> the last good AODB poll was
            @if ($feed['asOf']) <x-time :at="$feed['asOf']" /> Kigali time. @else never. @endif
            Data shown may be out of date.</p>
        </div>
    @endif
    {{ $slot }}
    <p class="footnote">Times are Kigali time (UTC+2); hover a time for UTC.</p>
</main>
@endif

@auth
    @livewireScripts
    <script src="{{ asset('js/hub.js') }}" data-session-ended="{{ route('login', ['session' => 'ended']) }}" defer></script>
@endauth
</body>
</html>
