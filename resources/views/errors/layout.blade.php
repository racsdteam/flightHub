{{-- Error pages: standalone, no session, auth or Livewire (they also render for 500 and maintenance). --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') · RAC Flight Data Hub</title>
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/favicon-32.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('images/apple-touch-icon-180.png') }}">
    <link rel="stylesheet" href="{{ asset('css/hub.css') }}">
</head>
<body class="standalone">
<header class="topbar">
    <a class="brand" href="{{ url('/') }}">
        <span class="brand-tile"><img src="{{ asset('images/rac-logo-80.png') }}" width="40" height="40" alt="Rwanda Airports Company"></span>
        <span>Flight Data Hub</span>
    </a>
</header>
<main id="main" class="standalone-main">
    <img class="auth-logo" src="{{ asset('images/rac-logo-240.png') }}" width="120" height="120" alt="">
    <section class="card auth-card error-page">
        <span class="code">HTTP @yield('code')</span>
        <h1>@yield('title')</h1>
        <p>@yield('message')</p>
        <div class="actions">@yield('actions')</div>
    </section>
</main>
</body>
</html>
