{{-- Error pages: standalone, no session, auth or Livewire (they also render for 500 and maintenance). --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') · RAC Flight Data Hub</title>
    <link rel="stylesheet" href="{{ asset('css/hub.css') }}">
</head>
<body class="standalone">
<header class="topbar">
    <a class="brand" href="{{ url('/') }}"><span class="brand-mark"><x-icon name="mark" size="22" /></span><span>RAC Flight Data Hub</span></a>
</header>
<main id="main" class="standalone-main">
    <section class="card auth-card error-page">
        <span class="code">HTTP @yield('code')</span>
        <h1>@yield('title')</h1>
        <p>@yield('message')</p>
        <div class="actions">@yield('actions')</div>
    </section>
</main>
</body>
</html>
