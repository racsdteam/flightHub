<x-layouts.app title="Too many attempts">
    <section class="card auth-card">
        <div class="auth-head"><x-icon name="warning" size="28" class="text-error" /><h1>Too many sign-in attempts</h1></div>
        <div class="banner banner-error"><x-icon name="clock" size="20" /><p>Too many failed sign-in attempts came from your network. Try again in {{ $seconds }} seconds.</p></div>
        <a class="button button-block" href="{{ route('login') }}">Back to sign-in</a>
    </section>
</x-layouts.app>
