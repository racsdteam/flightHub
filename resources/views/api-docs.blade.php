<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>API documentation · RAC Flight Data Hub</title>
    {{-- The hub frames the page; Swagger UI keeps its own stylesheet. All files are served by the hub (under its base path, D-061); no outside URL. --}}
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/favicon-32.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('images/apple-touch-icon-180.png') }}">
    <link rel="stylesheet" href="{{ asset('css/hub.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/swagger-ui/swagger-ui.css') }}">
</head>
<body class="standalone docs-frame">
<header class="topbar">
    <a class="brand" href="{{ url('/') }}">
        <span class="brand-tile"><img src="{{ asset('images/rac-logo-80.png') }}" width="40" height="40" alt="Rwanda Airports Company"></span>
        <span>Flight Data Hub</span>
    </a>
    <span class="docs-title">Distribution API documentation · read-only</span>
</header>
<main id="main">
    <div id="swagger-ui" data-spec-url="{{ route('api.openapi') }}"></div>
</main>
<script src="{{ asset('vendor/swagger-ui/swagger-ui-bundle.js') }}"></script>
<script src="{{ asset('js/api-docs.js') }}"></script>
</body>
</html>
